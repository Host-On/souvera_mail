<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Zentrale Anreicherung einer Mail für Push-Benachrichtigungen:
 * Betreff, Absender und Text-Vorschau per JMAP `Email/get` als User.
 *
 * Dedupe: war zuvor 1:1 doppelt implementiert (MailPushPoller::
 * fetchEmailDetails und StalwartWebhookController::fetchEmailEnrichment).
 *
 * Fehler sind bewusst nicht fatal — der Push geht dann ohne Anreicherung
 * raus (z. B. wenn OIDC auf der Instanz gerade nicht verfügbar ist).
 *
 * @return array{subject: string, from: string, preview: string}
 */
class MailEnricherService
{
    public function __construct(
        private readonly StalwartAdminService $stalwart,
        private readonly StalwartUserContext $userContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchDetails(string $userId, string $emailId): array
    {
        $empty = ['subject' => '', 'from' => '', 'preview' => ''];
        if ($emailId === '') {
            return $empty;
        }
        try {
            $bearer = $this->userContext->resolveBearer($userId);
            $accountId = $this->userContext->resolveAccountId($userId);
            $response = $this->stalwart->jmapCall(
                $bearer,
                [
                    ['Email/get', [
                        'accountId' => $accountId,
                        'ids' => [$emailId],
                        'properties' => ['subject', 'from', 'bodyValues'],
                        'bodyProperties' => ['preview'],
                        'fetchTextBodyValues' => true,
                        'maxBodyValueBytes' => 512,
                    ], 'g0'],
                ],
                ['urn:ietf:params:jmap:mail'],
            );
            $get = $this->stalwart->extractMethodResponse($response, 'Email/get');
            $list = $get['list'] ?? [];
            $first = \is_array($list) && isset($list[0]) && \is_array($list[0]) ? $list[0] : [];
            $subject = (string) ($first['subject'] ?? '');
            $from = '';
            $fromArr = $first['from'] ?? null;
            if (\is_array($fromArr) && isset($fromArr[0]) && \is_array($fromArr[0])) {
                $from = (string) ($fromArr[0]['name'] ?? $fromArr[0]['email'] ?? '');
            }
            $preview = (string) ($first['preview'] ?? '');
            if ($preview === '') {
                $preview = $this->extractPreview($first);
            }
            return ['subject' => $subject, 'from' => $from, 'preview' => $preview];
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: mail enrichment failed for "' . $userId . '": ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return $empty;
        }
    }

    /**
     * Extrahiert eine Text-Vorschau aus den bodyValues einer Email/get-Antwort.
     * Stalwart liefert bodyValues nur, wenn "bodyValues" in properties steht.
     *
     * @param array<string, mixed> $email Email-Objekt aus Email/get
     */
    public function extractPreview(array $email): string
    {
        $bodyValues = $email['bodyValues'] ?? null;
        if (!\is_array($bodyValues)) {
            return '';
        }
        $parts = [];
        foreach ($bodyValues as $part) {
            if (\is_array($part) && isset($part['value']) && \is_string($part['value'])) {
                $parts[] = $part['value'];
            }
        }
        $text = \trim(\preg_replace('/\s+/u', ' ', \implode(' ', $parts)) ?? '');
        return \mb_substr($text, 0, 300);
    }
}
