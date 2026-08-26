<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Termineinladungen (iCalendar/iTIP): parst Kalender-Anhänge einer Mail und
 * erlaubt Annehmen/Ablehnen/Vielleicht. Die Antwort legt das Ereignis im
 * CalDAV-Kalender des Nutzers an (bei accepted/tentative) und sendet eine
 * iTIP-Antwort (METHOD:REPLY) an den Organisator.
 *
 *   GET  /api/v2/calendar-invite/parse    ?emailId=&partId=
 *   POST /api/v2/calendar-invite/respond  {emailId, partId, response, calendarUri?}
 */
class CalendarInviteController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function parse(): JSONResponse
    {
        $emailId = \trim((string) $this->request->getParam('emailId'));
        $partId = \trim((string) $this->request->getParam('partId'));
        if ($emailId === '') {
            return new JSONResponse(['error' => 'emailId is required'], 400);
        }

        try {
            $ics = $this->fetchCalendarBlob($emailId, $partId);
            if ($ics === null) {
                return new JSONResponse(['error' => 'No calendar attachment found in this message'], 404);
            }
            $invite = $this->parseInvite($ics);
            if ($invite === null) {
                return new JSONResponse(['error' => 'Calendar data could not be parsed'], 422);
            }
            return new JSONResponse(['invite' => $invite]);
        } catch (\Throwable $e) {
            $this->logger->warning('Souvera Mail: calendar invite parse failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to parse calendar invitation'], 500);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function respond(): JSONResponse
    {
        $emailId = \trim((string) ($this->request->getParam('emailId') ?? ''));
        $partId = \trim((string) ($this->request->getParam('partId') ?? ''));
        $response = \trim((string) ($this->request->getParam('response') ?? ''));
        $calendarUri = \trim((string) ($this->request->getParam('calendarUri') ?? ''));

        if ($emailId === '') {
            return new JSONResponse(['error' => 'emailId is required'], 400);
        }
        if (!\in_array($response, ['accepted', 'declined', 'tentative'], true)) {
            return new JSONResponse(['error' => 'response must be accepted|declined|tentative'], 400);
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $ics = $this->fetchCalendarBlob($emailId, $partId);
            if ($ics === null) {
                return new JSONResponse(['error' => 'No calendar attachment found in this message'], 404);
            }
            $invite = $this->parseInvite($ics);
            if ($invite === null) {
                return new JSONResponse(['error' => 'Calendar data could not be parsed'], 422);
            }

            $userEmail = \strtolower((string) ($user->getEMailAddress() ?? ''));
            $attendee = $invite['attendee'] ?? $userEmail;
            $email = $userEmail !== '' ? $userEmail : $attendee;

            $calendarWritten = false;
            if ($response !== 'declined') {
                $calendarWritten = $this->writeEventToCalendar($user->getUID(), $ics, $invite, $calendarUri, $response);
            }

            $replySent = $this->sendItipReply($emailId, $invite, $email, $response);

            return new JSONResponse([
                'success' => true,
                'response' => $response,
                'calendarWritten' => $calendarWritten,
                'replySent' => $replySent,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Souvera Mail: calendar invite respond failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to process calendar invitation'], 500);
        }
    }

    /**
     * Lädt den ICS-Text des Kalender-Anhangs (oder -Inline-Parts) der Mail.
     */
    private function fetchCalendarBlob(string $emailId, string $partId): ?string
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return null;
        }

        $result = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => [$emailId],
            'properties' => ['subject', 'from', 'messageId', 'attachments', 'bodyValues', 'textBody', 'htmlBody'],
            'bodyProperties' => ['partId', 'blobId', 'type', 'name', 'disposition'],
            'fetchTextBodyValues' => true,
            'maxBodyValueBytes' => 1048576,
        ]);
        if (isset($result['error'])) {
            return null;
        }
        $email = $result['data']['list'][0] ?? null;
        if (!\is_array($email)) {
            return null;
        }
        $bodyValues = \is_array($email['bodyValues'] ?? null) ? $email['bodyValues'] : [];

        // 1) Expliziter Kalender-Anhang
        foreach (($email['attachments'] ?? []) as $att) {
            $type = \strtolower((string) ($att['type'] ?? ''));
            if ($type !== 'text/calendar') {
                continue;
            }
            $attPart = (string) ($att['partId'] ?? $att['blobId'] ?? '');
            if ($partId !== '' && $attPart !== $partId && ($att['blobId'] ?? '') !== $partId) {
                continue;
            }
            $value = $bodyValues[$attPart]['value'] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
            $blobId = (string) ($att['blobId'] ?? '');
            if ($blobId === '') {
                continue;
            }
            $blob = $this->jmap->singleCall('Blob/get', ['accountId' => $accountId, 'ids' => [$blobId]]);
            $data = $blob['data']['list'][0]['data:asText'] ?? null;
            if (\is_string($data) && $data !== '') {
                return $data;
            }
            $b64 = $blob['data']['list'][0]['data:asBase64'] ?? null;
            if (\is_string($b64) && $b64 !== '') {
                $decoded = \base64_decode($b64, true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }
        }

        // 2) Inline-Kalender-Part (text/calendar im Body)
        foreach (['textBody', 'htmlBody'] as $section) {
            foreach (($email[$section] ?? []) as $part) {
                $type = \strtolower((string) ($part['type'] ?? ''));
                if ($type !== 'text/calendar') {
                    continue;
                }
                $p = (string) ($part['partId'] ?? '');
                $value = $bodyValues[$p]['value'] ?? null;
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseInvite(string $ics): ?array
    {
        if (!\class_exists(\Sabre\VObject\Reader::class)) {
            return null;
        }
        try {
            $vcal = \Sabre\VObject\Reader::read($ics);
        } catch (\Throwable $e) {
            return null;
        }
        $method = (string) ($vcal->METHOD ?? 'REQUEST');
        $vevent = null;
        foreach ($vcal->getComponents() as $component) {
            if ($component instanceof \Sabre\VObject\Component\VEvent) {
                $vevent = $component;
                break;
            }
        }
        if ($vevent === null) {
            return null;
        }

        $organizer = (string) ($vevent->ORGANIZER ?? '');
        if (\stripos($organizer, 'mailto:') === 0) {
            $organizer = \substr($organizer, 7);
        }

        $attendee = '';
        foreach (($vevent->ATTENDEE ?? []) as $att) {
            $raw = (string) $att;
            if (\stripos($raw, 'mailto:') === 0) {
                $raw = \substr($raw, 7);
            }
            if ($raw !== '') {
                $attendee = $raw;
                break;
            }
        }

        $parseDate = static function ($property): ?array {
            if ($property === null) {
                return null;
            }
            try {
                $dt = $property->getDateTime();
                return ['iso' => $dt->format('c'), 'allDay' => !$property->hasTime()];
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [
            'uid' => (string) ($vevent->UID ?? ''),
            'sequence' => (int) ($vevent->SEQUENCE ?? 0),
            'method' => $method,
            'organizer' => $organizer,
            'attendee' => $attendee,
            'summary' => (string) ($vevent->SUMMARY ?? ''),
            'location' => (string) ($vevent->LOCATION ?? ''),
            'description' => (string) ($vevent->DESCRIPTION ?? ''),
            'dtstart' => $parseDate($vevent->DTSTART),
            'dtend' => $parseDate($vevent->DTEND ?? $vevent->DUE),
        ];
    }

    /**
     * Legt das Ereignis im CalDAV-Kalender des Nutzers an bzw. aktualisiert
     * es anhand der UID.
     *
     * @param array<string, mixed> $invite
     */
    private function writeEventToCalendar(string $userId, string $ics, array $invite, string $calendarUri, string $response): bool
    {
        if (!\class_exists(\OCA\DAV\CalDAV\CalDavBackend::class)) {
            return false;
        }
        $backend = \OCP\Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $principal = 'principals/users/' . $userId;
        $calendars = $backend->getCalendarsForUser($principal);
        if ($calendars === []) {
            return false;
        }

        // Zielkalender: explizit gewählt, sonst erster persönlicher Kalender mit VEVENT-Unterstützung.
        $target = null;
        if ($calendarUri !== '') {
            foreach ($calendars as $cal) {
                if (($cal['uri'] ?? '') === $calendarUri) {
                    $target = $cal;
                    break;
                }
            }
        }
        if ($target === null) {
            foreach ($calendars as $cal) {
                $components = (string) ($cal['components'] ?? '');
                if ($components === '' || \str_contains($components, 'VEVENT')) {
                    $target = $cal;
                    break;
                }
            }
        }
        if ($target === null) {
            return false;
        }

        $uid = $invite['uid'] !== '' ? $invite['uid'] : ('souvera-' . \bin2hex(\random_bytes(8)));
        $objectUri = $uid . '.ics';

        // Antwort-Status im ICS vermerken, damit der Client den Zustand zeigt.
        try {
            $vcal = \Sabre\VObject\Reader::read($ics);
            $vevent = null;
            foreach ($vcal->getComponents() as $component) {
                if ($component instanceof \Sabre\VObject\Component\VEvent) {
                    $vevent = $component;
                    break;
                }
            }
            if ($vevent !== null) {
                $partstat = $response === 'accepted' ? 'ACCEPTED' : 'TENTATIVE';
                $vevent->remove('X-SOUVERA-RESPONSE');
                $vevent->add('X-SOUVERA-RESPONSE', $partstat);
                $ics = $vcal->serialize();
            }
        } catch (\Throwable $e) {
            // Original-ICS verwenden.
        }

        try {
            $existing = $backend->getCalendarObject($target['id'], $objectUri, 1);
            if ($existing !== null) {
                $backend->updateCalendarObject($target['id'], $objectUri, $ics);
            } else {
                $backend->createCalendarObject($target['id'], $objectUri, $ics);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Souvera Mail: calendar write failed: ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }

    /**
     * Sendet die iTIP-Antwort (METHOD:REPLY) per JMAP an den Organisator.
     *
     * @param array<string, mixed> $invite
     */
    private function sendItipReply(string $emailId, array $invite, string $userEmail, string $response): bool
    {
        $organizer = (string) ($invite['organizer'] ?? '');
        if ($organizer === '' || !\filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if ($invite['uid'] === '') {
            return false;
        }

        $partstat = ['accepted' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'declined' => 'DECLINED'][$response];

        $vcal = new \Sabre\VObject\Component\VCalendar([
            'METHOD' => 'REPLY',
            'PRODID' => '-//Host-On//Souvera Mail//DE',
        ]);
        $vcal->add('VEVENT', [
            'UID' => $invite['uid'],
            'SEQUENCE' => (string) $invite['sequence'],
            'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'ORGANIZER' => 'mailto:' . $organizer,
            'SUMMARY' => $invite['summary'],
            'DTSTART' => $invite['dtstart']['iso'] ?? '',
            'DTEND' => $invite['dtend']['iso'] ?? '',
            'ATTENDEE' => ['mailto:' . $userEmail, ['PARTSTAT' => $partstat]],
        ]);
        $replyIcs = $vcal->serialize();

        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return false;
        }

        $summary = $invite['summary'] !== '' ? $invite['summary'] : 'Kalendereinladung';
        $subject = ($partstat === 'DECLINED' ? 'Abgelehnt: ' : 'Zugesagt: ') . $summary;
        $text = "Diese Nachricht ist eine Kalenderantwort (iTIP).\n\n"
            . $summary . "\n"
            . 'Teilnehmer: ' . $userEmail . "\n"
            . 'Status: ' . $partstat . "\n";

        $emailObj = [
            'subject' => $subject,
            'from' => [['email' => $userEmail]],
            'to' => [['email' => $organizer]],
            'keywords' => ['$draft' => true, '$seen' => true],
            'textBody' => [['partId' => '1', 'type' => 'text/plain']],
            'bodyValues' => [
                '1' => ['value' => $text],
                '2' => ['value' => $replyIcs],
            ],
            'attachments' => [[
                'blobId' => $this->uploadBlob($accountId, $replyIcs),
                'type' => 'text/calendar; charset=utf-8; method=REPLY',
                'name' => 'reply.ics',
                'size' => \strlen($replyIcs),
            ]],
        ];

        $result = $this->jmap->call([
            ['Email/set', ['accountId' => $accountId, 'create' => ['draft1' => $emailObj]]],
            ['EmailSubmission/set', [
                'accountId' => $accountId,
                'create' => ['send1' => [
                    'emailId' => '#draft1',
                    'identityId' => $accountId,
                ]],
            ]],
        ]);

        return !isset($result['error']);
    }

    private function uploadBlob(string $accountId, string $data): string
    {
        $result = $this->jmap->singleCall('Blob/upload', [
            'accountId' => $accountId,
            'data' => [['type' => 'application/octet-stream', 'data:asBase64' => \base64_encode($data)]],
        ]);
        $blobId = $result['data']['list'][0]['blobId'] ?? ($result['data']['list'][0]['id'] ?? '');
        return (string) $blobId;
    }
}
