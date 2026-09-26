<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\ExternalAccountService;
use OCA\SouveraMail\Service\ExternalSmtpService;
use OCA\SouveraMail\Service\SignatureStoreService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class V2ComposeController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
        private StalwartUserContext $userContext,
        private IUserSession $userSession,
        private SignatureStoreService $signatureStore,
        private ExternalAccountService $externalAccounts,
        private ExternalSmtpService $externalSmtp,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/identities
     */
    #[NoAdminRequired]
    public function identities(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->singleCall('Identity/get', ['accountId' => $accountId]);
        $list = $result['data']['list'] ?? [];

        if (empty($list)) {
            $list = [['id' => $accountId, 'name' => '', 'email' => '']];
        }

        $identities = \array_map(fn($i) => [
            'id' => $i['id'] ?? '',
            'name' => $i['name'] ?? '',
            'email' => $i['email'] ?? '',
        ], $list);

        // Add aliases from souvera_central (email addresses that deliver
        // to this mailbox but are NOT JMAP identities).
        $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
        $uid = $user !== null ? $user->getUID() : '';
        $aliases = $this->resolveAliases();
        $knownEmails = \array_map(fn($i) => \strtolower((string) $i['email']), $identities);
        foreach ($aliases as $alias) {
            if (\in_array(\strtolower($alias), $knownEmails, true)) continue;
            $identities[] = [
                'id' => 'alias:' . $alias,
                'name' => $uid !== '' ? ($this->resolveAliasDisplayName($uid, $alias) ?? '') : '',
                'email' => $alias,
                'isAlias' => true,
            ];
        }

        // Add external IMAP/SMTP accounts as sendable identities (id
        // "ext:<accountId>") — their messages are sent through the
        // external SMTP server, see send().
        if ($uid !== '') {
            foreach ($this->externalAccounts->listForUser($uid) as $ext) {
                $identities[] = [
                    'id' => 'ext:' . $ext['id'],
                    'name' => '',
                    'email' => (string) ($ext['email'] ?? ''),
                    'isExternal' => true,
                ];
            }
        }

        return new JSONResponse(['identities' => $identities]);
    }

    /**
     * PUT /apps/souvera_mail/api/v2/identities/{id}
     * Body: {name: "..."}
     */
    #[NoAdminRequired]
    public function updateIdentity(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $name = \trim((string) ($body['name'] ?? ''));

        $result = $this->jmap->singleCall('Identity/set', [
            'accountId' => $accountId,
            'update' => [$id => ['name' => $name]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }
        $data = $result['data'] ?? [];
        if (isset($data['notUpdated'][$id])) {
            $r = $data['notUpdated'][$id];
            return new JSONResponse([
                'error' => 'Update rejected: ' . ($r['description'] ?? $r['type'] ?? 'notUpdated'),
            ], 422);
        }
        if (!\array_key_exists($id, $data['updated'] ?? [])) {
            return new JSONResponse(['error' => 'Update not applied'], 500);
        }
        return new JSONResponse(['success' => true, 'name' => $name]);
    }

    /**
     * PUT /apps/souvera_mail/api/v2/identities/{id}/signature
     * Body: {html: "...", enabled: bool}
     *
     * Per-identity signature settings. Aliases and external accounts are
     * accepted (alias:... / ext:...). Empty html = no signature for this
     * identity, but the position settings are kept.
     */
    #[NoAdminRequired]
    public function updateIdentitySignature(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $uid = $user->getUID();

        // The id must be a REAL identity of this user (JMAP identity,
        // alias or external account) — never trust the client-provided id.
        if (\str_starts_with((string) $id, 'alias:')) {
            $alias = \strtolower(\trim(\substr((string) $id, 6)));
            $allowedAliases = \array_map('strtolower', $this->resolveAliases());
            if ($alias === '' || !\in_array($alias, $allowedAliases, true)) {
                return new JSONResponse(['error' => 'Unknown alias address'], 400);
            }
            // Canonical form — same key as used by identities() and send().
            $id = 'alias:' . $alias;
        } elseif (\str_starts_with((string) $id, 'ext:')) {
            $extId = \trim(\substr((string) $id, 4));
            if ($extId === '' || $this->externalAccounts->getWithPassword($uid, $extId) === null) {
                return new JSONResponse(['error' => 'Unknown external account'], 400);
            }
            $id = 'ext:' . $extId;
        } else {
            $result = $this->jmap->singleCall('Identity/get', ['accountId' => $accountId]);
            $list = $result['data']['list'] ?? [];
            $known = \array_map(fn($i) => $i['id'] ?? '', $list);
            if (!\in_array((string) $id, $known, true)) {
                return new JSONResponse(['error' => 'Unknown identity'], 400);
            }
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $html = \trim((string) ($body['html'] ?? ''));
        $enabled = (bool) ($body['enabled'] ?? false);
        $signaturePosition = \in_array((string) ($body['signaturePosition'] ?? ''), ['above', 'below'], true)
            ? (string) $body['signaturePosition'] : 'above';
        $replyPosition = \in_array((string) ($body['replyPosition'] ?? ''), ['above', 'below'], true)
            ? (string) $body['replyPosition'] : 'above';

        $raw = $this->config->getUserValue($uid, 'souvera_mail', 'pref_identity_signatures', '');
        $map = \json_decode($raw, true);
        if (!\is_array($map)) {
            $map = [];
        }

        // Empty html = no signature for this identity, but the position
        // settings are kept so they still apply when replying.
        if ($html === '') {
            $this->signatureStore->deleteFor($uid, $id);
            $map[$id] = [
                'enabled' => 0,
                'signaturePosition' => $signaturePosition,
                'replyPosition' => $replyPosition,
            ];
        } else {
            try {
                $this->signatureStore->writeFor($uid, $id, $html);
            } catch (\Throwable $e) {
                return new JSONResponse(['error' => 'Failed to save signature'], 500);
            }
            $map[$id] = [
                'enabled' => $enabled ? 1 : 0,
                'signaturePosition' => $signaturePosition,
                'replyPosition' => $replyPosition,
            ];
        }

        $this->config->setUserValue(
            $uid,
            'souvera_mail',
            'pref_identity_signatures',
            \json_encode($map, \JSON_UNESCAPED_SLASHES)
        );

        return new JSONResponse(['success' => true]);
    }

    /**
     * Resolve the user's email aliases via souvera_central.
     *
     * @return string[]
     */
    private function resolveAliases(): array
    {
        try {
            $user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
            if ($user === null) return [];
            $email = $user->getEMailAddress();
            if ($email === null || $email === '') return [];
            if (!\class_exists('OCA\\SouveraCentral\\Service\\StalwartService')) return [];
            $stalwart = \OCP\Server::get('OCA\\SouveraCentral\\Service\\StalwartService');
            $emails = $stalwart->getEmails($email);
            // getEmails returns primary + aliases — remove the primary.
            $primary = \strtolower($email);
            return \array_values(\array_filter($emails, fn($e) => \strtolower((string) $e) !== $primary));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Look up a user-defined display name for an alias address.
     * Aliases are not JMAP identities, so the name is stored in user
     * preferences (pref_alias_names — JSON map alias email → name).
     */
    private function resolveAliasDisplayName(string $uid, string $alias): ?string
    {
        try {
            $config = \OCP\Server::get(\OCP\IConfig::class);
            $raw = $config->getUserValue($uid, 'souvera_mail', 'pref_alias_names', '');
            $map = \json_decode($raw, true);
            if (!\is_array($map)) return null;
            $value = $map[\strtolower($alias)] ?? null;
            if (!\is_string($value)) return null;
            $name = \trim($value);
            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/send
     */
    #[NoAdminRequired]
    public function send(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        if (!\is_array($body)) {
            return new JSONResponse(['error' => 'Invalid JSON'], 400);
        }

        $toAddr = \is_array($body['to'] ?? null) ? $body['to'] : [];
        $ccAddr = \is_array($body['cc'] ?? null) ? $body['cc'] : [];
        $bccAddr = \is_array($body['bcc'] ?? null) ? $body['bcc'] : [];
        $subject = \trim((string) ($body['subject'] ?? ''));
        $bodyHtml = \trim((string) ($body['bodyHtml'] ?? ''));
        $bodyPlain = \trim((string) ($body['bodyPlain'] ?? ''));
        $attachments = $body['attachments'] ?? [];
        $inReplyTo = $body['inReplyTo'] ?? null;
        $references = $body['references'] ?? null;
        $identityId = $body['identityId'] ?? null;

        if ($toAddr === [] && $ccAddr === [] && $bccAddr === []) {
            return new JSONResponse(['error' => 'No recipients'], 400);
        }

        $user = $this->userSession->getUser();
        $userEmail = $this->userContext->resolveEmail($user->getUID());

        // External accounts (id "ext:<accountId>") send through the
        // external SMTP server — never through Stalwart/JMAP.
        if ($identityId !== null && \str_starts_with((string) $identityId, 'ext:')) {
            $extId = \trim(\substr((string) $identityId, 4));
            $extAccount = $extId !== '' ? $this->externalAccounts->getWithPassword($user->getUID(), $extId) : null;
            if ($extAccount === null) {
                return new JSONResponse(['error' => 'Unknown external account'], 400);
            }
            // Only fresh uploads (base64 data) can be attached to an
            // external send — blobIds reference the Stalwart store and
            // are not readable for the external path.
            $extAttachments = [];
            foreach ($attachments as $att) {
                if (!\is_array($att)) continue;
                $data = (string) ($att['data'] ?? '');
                if ($data === '') {
                    if (!empty($att['blobId'])) {
                        return new JSONResponse(['error' => 'Forwarded attachments are not available for external sending'], 400);
                    }
                    continue;
                }
                $extAttachments[] = [
                    'name' => \trim((string) ($att['name'] ?? 'attachment')),
                    'type' => \trim((string) ($att['type'] ?? 'application/octet-stream')),
                    'data' => $data,
                ];
            }
            try {
                $this->externalSmtp->send(
                    $extAccount,
                    (string) ($extAccount['email'] ?? $userEmail),
                    '',
                    $toAddr, $ccAddr, $bccAddr,
                    $subject, $bodyHtml, $bodyPlain,
                    $extAttachments,
                );
                return new JSONResponse(['success' => true, 'submitted' => true]);
            } catch (\Throwable $e) {
                $this->logger->warning('External send failed: ' . $e->getMessage(), [
                    'app' => 'souvera_mail', 'exception' => $e,
                ]);
                return new JSONResponse(['error' => $e->getMessage()], 502);
            }
        }

        // Alias identities (id "alias:foo@bar.com") are not JMAP identities —
        // the FROM header gets the alias address while the submission uses
        // the primary JMAP identity. The alias MUST be one of the user's
        // real aliases — never trust the client-provided address verbatim.
        $fromEmail = $userEmail;
        $fromName = null;
        if ($identityId !== null && \str_starts_with((string) $identityId, 'alias:')) {
            $alias = \strtolower(\trim(\substr((string) $identityId, 6)));
            $allowedAliases = \array_map('strtolower', $this->resolveAliases());
            if ($alias === '' || !\in_array($alias, $allowedAliases, true)) {
                return new JSONResponse(['error' => 'Unknown alias address'], 400);
            }
            $fromEmail = $alias;
            $fromName = $this->resolveAliasDisplayName($user->getUID(), $alias);
            $identityId = $this->resolveIdentityId($accountId);
            if ($identityId === null) {
                return new JSONResponse(['error' => 'No JMAP identity found'], 500);
            }
        } elseif ($identityId === null || $identityId === '') {
            $identityId = $this->resolveIdentityId($accountId);
            if ($identityId === null) {
                return new JSONResponse(['error' => 'No JMAP identity found'], 500);
            }
        }

        // Resolve Drafts + Sent mailboxes in ONE call.
        $mailboxes = $this->resolveMailboxes($accountId);
        $draftsId = $mailboxes['drafts'] ?? null;
        $sentId = $mailboxes['sent'] ?? null;
        if ($draftsId === null) {
            $draftsId = $sentId;
        }

        // Upload NEW attachments (those with data key).
        $blobIds = [];
        foreach ($attachments as $index => $att) {
            $rawData = \base64_decode((string) ($att['data'] ?? ''), true);
            if ($rawData === false || $rawData === '') {
                continue;
            }
            $name = $att['name'] ?? "attachment_{$index}.bin";
            $type = $att['type'] ?? 'application/octet-stream';
            $upload = $this->uploadBlob($accountId, $rawData, $type, $name);
            if ($upload !== null) {
                $blobIds[] = $upload;
            }
        }

        // Forward-attachments (already uploaded, just blobId).
        $fwdBlobIds = [];
        foreach ($attachments as $att) {
            $preExistingBlobId = $att['blobId'] ?? null;
            if ($preExistingBlobId !== null && empty($att['data'] ?? '')) {
                $fwdBlobIds[] = [
                    'blobId' => $preExistingBlobId,
                    'name' => $att['name'] ?? 'attachment',
                    'type' => $att['type'] ?? 'application/octet-stream',
                    'size' => $att['size'] ?? 0,
                ];
            }
        }

        // Build Email/create object — saved in Drafts with draft+seen keywords.
        $emailObj = $this->buildEmailObject(
            $fromEmail, $toAddr, $ccAddr, $bccAddr,
            $subject, $bodyHtml, $bodyPlain,
            \array_merge($blobIds, $fwdBlobIds),
            $inReplyTo, $references, $draftsId, $fromName
        );

        // Step 1: Email/set — create in drafts, then patch to sent
        // Step 2: EmailSubmission/set — submit and patch email to sent mailbox
        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'create' => ['draft1' => $emailObj],
            ]],
            ['EmailSubmission/set', [
                'accountId' => $accountId,
                'onSuccessUpdateEmail' => [
                    '#send1' => $this->buildSentPatch($draftsId, $sentId),
                ],
                'create' => ['send1' => [
                    'emailId' => '#draft1',
                    'identityId' => $identityId,
                ]],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        $responses = $result['responses'] ?? [];

        $emailResp = null;
        $submissionResp = null;
        foreach ($responses as $resp) {
            // Keep the FIRST match per method: the envelope produces THREE
            // responses — the explicit Email/set (created.draft1), the
            // EmailSubmission/set (created.send1) and an IMPLICIT update-only
            // Email/set triggered by onSuccessUpdateEmail (no "created").
            // The implicit one must never shadow the explicit creation.
            if ($emailResp === null && $resp['name'] === 'Email/set') $emailResp = $resp;
            if ($submissionResp === null && $resp['name'] === 'EmailSubmission/set') $submissionResp = $resp;
        }

        $created = $emailResp['args']['created']['draft1'] ?? null;
        $submitted = $submissionResp['args']['created']['send1'] ?? null;
        $submitFailed = $submissionResp['args']['notCreated']['send1'] ?? null;
        if ($created === null) {
            $this->logger->warning(
                'Souvera Mail: Email/set reported no created draft1. '
                . 'Email/set args: ' . \json_encode($emailResp['args'] ?? null, JSON_UNESCAPED_SLASHES)
                . ' | EmailSubmission/set args: ' . \json_encode($submissionResp['args'] ?? null, JSON_UNESCAPED_SLASHES),
                ['app' => 'souvera_mail']
            );
        }
        if ($created === null && $submitted === null) {
            return new JSONResponse([
                'error' => $this->humanSendError($submitFailed, 'Email creation failed'),
                'detail' => $submitFailed !== null ? $submitFailed : ($emailResp['args'] ?? []),
            ], 500);
        }
        // Created, aber NICHT submitted (z. B. tooLarge/noQuota beim
        // EmailSubmission/set): der Versand ist FEHLGESCHLAGEN — Erfolg zu
        // melden wäre ein stiller Versandverlust. Der erzeugte Entwurf bleibt
        // bewusst liegen (der User kann ihn korrigieren und erneut senden).
        if ($submitted === null) {
            $reason = $submitFailed['description'] ?? $submitFailed['type'] ?? null;
            $this->logger->warning(
                'Souvera Mail: EmailSubmission/set rejected the mail'
                . ($reason !== null ? ': ' . \json_encode($reason, JSON_UNESCAPED_SLASHES) : ''),
                ['app' => 'souvera_mail']
            );
            return new JSONResponse([
                'error' => $this->humanSendError(
                    \is_array($reason) ? $reason : ($submitFailed ?? []),
                    'Die Nachricht konnte nicht übermittelt werden'
                ),
                'detail' => $submitFailed ?? [],
            ], 502);
        }
        return new JSONResponse([
            'success' => true,
            'draftId' => $created['id'] ?? '',
            'submitted' => true,
        ]);
    }

    /**
     * Übersetzt JMAP-Submission-Reject-Gründe (Stalwart) in verständliche
     * deutsche Meldungen — der Kunde soll den GRUND sehen (Anhang zu groß,
     * Quote, ungültige Adresse …), nicht nur „fehlgeschlagen".
     *
     * @param mixed $reason notCreated-Eintrag (array mit type/description) oder String
     */
    private function humanSendError(mixed $reason, string $fallback): string {
        $type = '';
        $description = '';
        if (\is_array($reason)) {
            $type = \strtolower((string) ($reason['type'] ?? ''));
            $description = \trim((string) ($reason['description'] ?? ''));
        } elseif (\is_string($reason) && $reason !== '') {
            $description = \trim($reason);
            if (\preg_match('/toos?large|size|quota/i', $description)) { $type = 'tooLarge'; }
            if (\preg_match('/invalid.*mail|bad.*address|noSuch/i', $description)) { $type = 'invalidEmail'; }
        }

        $msg = match ($type) {
            'toolarge', 'sizelimit' => 'Die Nachricht ist zu groß (Limit des Mailservers überschritten — meist durch Anhänge). Verkleinere die Anhänge oder entferne sie.',
            'noquota', 'overquota' => 'Das Postfach- oder Versandlimit ist erschöpft — bitte den Administrator informieren.',
            'invalidemail', 'bademail', 'nosuchrecipient' => 'Eine Empfängeradresse ist ungültig oder wird vom Mailserver abgelehnt.',
            'forbidden' => 'Der Mailserver hat den Versand abgelehnt (Berechtigung/Spamschutz).',
            default => '',
        };

        if ($msg === '' && $description !== '') {
            return $fallback . ' — ' . \mb_substr($description, 0, 200);
        }
        return $msg !== '' ? $msg : $fallback;
    }

    /**
     * GET /apps/souvera_mail/api/v2/drafts/resolve?inReplyTo=<messageId>
     *
     * Findet den bereits existierenden Entwurf für einen Antwort-Kontext
     * (Drafts-Ordner, $draft-Keyword + In-Reply-To-Match) und liefert
     * draftId + vollständigen Inhalt. Der Composer lädt damit den bestehenden
     * Entwurf statt bei jedem Öffnen/Schließen-Zyklus einen neuen zu erzeugen
     * (Draft-Flut).
     */
    #[NoAdminRequired]
    public function resolveDraft(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $inReplyTo = \trim((string) ($this->request->getParam('inReplyTo') ?? ''));
        if ($inReplyTo === '') {
            return new JSONResponse(['error' => 'inReplyTo required'], 400);
        }

        $draftsId = $this->resolveMailboxId($accountId, 'drafts');
        if ($draftsId === null) {
            return new JSONResponse(['found' => false]);
        }

        // Kandidaten: Drafts im Drafts-Ordner ($draft-Keyword), begrenzt.
        $query = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => ['inMailbox' => $draftsId, 'hasKeyword' => '$draft'],
            'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            'limit' => 25,
        ]);
        if (isset($query['error'])) {
            return new JSONResponse(['error' => 'Draft query failed', 'detail' => $query['error']], 500);
        }
        $ids = $query['data']['ids'] ?? [];
        if (!\is_array($ids) || $ids === []) {
            return new JSONResponse(['found' => false]);
        }

        $get = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => $ids,
            'properties' => ['id', 'subject', 'inReplyTo', 'to', 'cc', 'bcc'],
            'bodyProperties' => ['textBody', 'htmlBody', 'preview'],
            'fetchTextBodyValues' => true,
            'fetchHTMLBodyValues' => true,
            'maxBodyValueBytes' => 1048576,
        ]);
        if (isset($get['error'])) {
            return new JSONResponse(['error' => 'Draft fetch failed', 'detail' => $get['error']], 500);
        }
        foreach (($get['data']['list'] ?? []) as $email) {
            if (!\is_array($email)) { continue; }
            $refs = $email['inReplyTo'] ?? null;
            $refList = \is_array($refs) ? $refs : [];
            if (\in_array($inReplyTo, $refList, true)) {
                // Draft-Inhalt für den Editor extrahieren (gleiches Muster
                // wie die Detail-Ansicht: textBody bevorzugt, HTML fallback).
                $bodyHtml = '';
                $bodyPlain = '';
                $htmlArr = $email['htmlBody'] ?? [];
                $textArr = $email['textBody'] ?? [];
                if (\is_array($htmlArr) && isset($htmlArr[0]['partId'])) {
                    $pid = (string) $htmlArr[0]['partId'];
                    $bv = $email['bodyValues'][$pid]['value'] ?? null;
                    if (\is_string($bv)) { $bodyHtml = $bv; }
                }
                if (\is_array($textArr) && isset($textArr[0]['partId'])) {
                    $pid = (string) $textArr[0]['partId'];
                    $bv = $email['bodyValues'][$pid]['value'] ?? null;
                    if (\is_string($bv)) { $bodyPlain = $bv; }
                }
                $to = [];
                foreach (($email['to'] ?? []) as $r) {
                    if (\is_array($r) && isset($r['email'])) { $to[] = $r['email']; }
                }
                $cc = [];
                foreach (($email['cc'] ?? []) as $r) {
                    if (\is_array($r) && isset($r['email'])) { $cc[] = $r['email']; }
                }
                $bcc = [];
                foreach (($email['bcc'] ?? []) as $r) {
                    if (\is_array($r) && isset($r['email'])) { $bcc[] = $r['email']; }
                }
                return new JSONResponse([
                    'found' => true,
                    'draftId' => (string) ($email['id'] ?? ''),
                    'subject' => (string) ($email['subject'] ?? ''),
                    'to' => $to,
                    'cc' => $cc,
                    'bcc' => $bcc,
                    'bodyHtml' => $bodyHtml,
                    'bodyPlain' => $bodyPlain,
                ]);
            }
        }
        return new JSONResponse(['found' => false]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/drafts
     */
    #[NoAdminRequired]
    public function createDraft(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        if (!\is_array($body)) {
            return new JSONResponse(['error' => 'Invalid JSON'], 400);
        }

        $toAddr = \is_array($body['to'] ?? null) ? $body['to'] : [];
        $ccAddr = \is_array($body['cc'] ?? null) ? $body['cc'] : [];
        $bccAddr = \is_array($body['bcc'] ?? null) ? $body['bcc'] : [];
        $subject = \trim((string) ($body['subject'] ?? ''));
        $bodyHtml = \trim((string) ($body['bodyHtml'] ?? ''));
        $bodyPlain = \trim((string) ($body['bodyPlain'] ?? ''));

        $user = $this->userSession->getUser();
        $userEmail = $this->userContext->resolveEmail($user->getUID());

        $draftsId = $this->resolveMailboxId($accountId, 'drafts');

        $emailObj = $this->buildEmailObject(
            $userEmail, $toAddr, $ccAddr, $bccAddr,
            $subject, $bodyHtml, $bodyPlain,
            [], null, null, $draftsId
        );
        $emailObj['keywords'] = ['$draft' => true];

        $result = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'create' => ['draft1' => $emailObj],
        ]);

        $created = $result['data']['created']['draft1'] ?? null;
        // notCreated (z. B. zu großes Draft): den Grund zurückgeben statt einer
        // leeren ID — sonst startet der Frontend-Autosave alle 3 s einen neuen
        // Create-Versuch und erzeugt eine Draft-Flut.
        if ($created === null) {
            $notCreated = $result['data']['notCreated']['draft1'] ?? [];
            $reason = \is_array($notCreated)
                ? (string) ($notCreated['description'] ?? $notCreated['type'] ?? 'Draft create rejected')
                : 'Draft create rejected';
            return new JSONResponse(['error' => 'Entwurf konnte nicht gespeichert werden: ' . $reason], 500);
        }
        return new JSONResponse([
            'success' => true,
            'draftId' => $created['id'] ?? '',
        ]);
    }

    /**
     * PUT /apps/souvera_mail/api/v2/drafts/{id}
     */
    #[NoAdminRequired]
    public function updateDraft(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        if (!\is_array($body)) {
            return new JSONResponse(['error' => 'Invalid JSON'], 400);
        }

        $toAddr = \is_array($body['to'] ?? null) ? $body['to'] : [];
        $ccAddr = \is_array($body['cc'] ?? null) ? $body['cc'] : [];
        $bccAddr = \is_array($body['bcc'] ?? null) ? $body['bcc'] : [];
        $subject = \trim((string) ($body['subject'] ?? ''));
        $bodyHtml = \trim((string) ($body['bodyHtml'] ?? ''));
        $bodyPlain = \trim((string) ($body['bodyPlain'] ?? ''));

        $user = $this->userSession->getUser();
        $userEmail = $this->userContext->resolveEmail($user->getUID());

        $draftsId = $this->resolveMailboxId($accountId, 'drafts');

        $emailObj = $this->buildEmailObject(
            $userEmail, $toAddr, $ccAddr, $bccAddr,
            $subject, $bodyHtml, $bodyPlain,
            [], null, null, $draftsId
        );
        $emailObj['keywords'] = ['$draft' => true];

        // Update the draft IN PLACE (Email/set update). The previous
        // destroy+create replacement assigned a new id on every autosave —
        // and the client never picked it up, so every autosave left yet
        // another draft behind.
        $result = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'update' => [$id => $emailObj],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse(['error' => 'Draft update failed', 'detail' => $result['error']], 500);
        }
        $updated = $result['data']['updated'][$id] ?? null;
        $notUpdated = $result['data']['notUpdated'][$id] ?? null;
        if ($notUpdated !== null) {
            // Draft vanished (e.g. destroyed elsewhere) — fall back to create.
            $create = $this->jmap->singleCall('Email/set', [
                'accountId' => $accountId,
                'create' => ['draft1' => $emailObj],
            ]);
            if (isset($create['error'])) {
                return new JSONResponse(['error' => 'Draft recreate failed', 'detail' => $create['error']], 500);
            }
            $created = $create['data']['created']['draft1'] ?? null;
            return new JSONResponse([
                'success' => true,
                'draftId' => $created['id'] ?? '',
            ]);
        }

        return new JSONResponse([
            'success' => true,
            'draftId' => $updated['id'] ?? $id,
        ]);
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/drafts/{id}
     */
    #[NoAdminRequired]
    public function deleteDraft(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'destroy' => [$id],
        ]);

        return new JSONResponse(['success' => true]);
    }

    private function buildEmailObject(
        string $userEmail,
        array $toAddr, array $ccAddr, array $bccAddr,
        string $subject, string $bodyHtml, string $bodyPlain,
        array $attachmentBlobs,
        ?string $inReplyTo, ?string $references,
        ?string $draftsId,
        ?string $fromName = null,
    ): array {
        $fromHeader = ['email' => $userEmail];
        if ($fromName !== null && $fromName !== '') {
            $fromHeader['name'] = $fromName;
        }
        $emailObj = [
            'subject' => $subject,
            'from' => [$fromHeader],
            'to' => \array_map(fn($e) => ['email' => \trim($e)], $toAddr),
            'keywords' => ['$draft' => true, '$seen' => true],
        ];

        if ($draftsId !== null) {
            $emailObj['mailboxIds'] = [$draftsId => true];
        }
        if ($ccAddr !== []) {
            $emailObj['cc'] = \array_map(fn($e) => ['email' => \trim($e)], $ccAddr);
        }
        if ($bccAddr !== []) {
            $emailObj['bcc'] = \array_map(fn($e) => ['email' => \trim($e)], $bccAddr);
        }
        if ($inReplyTo !== null) {
            $emailObj['inReplyTo'] = [$inReplyTo];
        }
        if ($references !== null) {
            $emailObj['references'] = [$references];
        }

        $partCount = 0;
        if ($bodyHtml !== '') {
            $partCount++;
            $emailObj['htmlBody'] = [['partId' => (string) $partCount, 'type' => 'text/html']];
            $emailObj['bodyValues'] = [(string) $partCount => ['value' => $bodyHtml]];
        }
        if ($bodyPlain !== '' || $bodyHtml === '') {
            $partCount++;
            $emailObj['textBody'] = [['partId' => (string) $partCount, 'type' => 'text/plain']];
            $emailObj['bodyValues'] = $emailObj['bodyValues'] ?? [];
            $emailObj['bodyValues'][(string) $partCount] = ['value' => $bodyPlain ?: $subject];
        }

        if ($attachmentBlobs !== []) {
            $emailObj['attachments'] = \array_map(fn($b) => [
                'blobId' => $b['blobId'],
                'type' => $b['type'],
                'name' => $b['name'],
                'size' => $b['size'] ?? 0,
            ], $attachmentBlobs);
        }

        return $emailObj;
    }

    private function resolveIdentityId(string $accountId): ?string
    {
        $result = $this->jmap->singleCall('Identity/get', ['accountId' => $accountId]);
        $list = $result['data']['list'] ?? [];
        if (\count($list) > 0) {
            return $list[0]['id'] ?? null;
        }
        return $accountId;
    }

    private function resolveMailboxId(string $accountId, string $role): ?string
    {
        $result = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
        foreach ($result['data']['list'] ?? [] as $mb) {
            if (($mb['role'] ?? '') === $role) {
                return $mb['id'];
            }
        }
        return null;
    }

    /**
     * onSuccessUpdateEmail patch — only REAL mailbox ids, never literal
     * 'drafts'/'sent' strings (invalid ids fail silently, so the sent copy
     * would never happen).
     */
    private function buildSentPatch(?string $draftsId, ?string $sentId): array
    {
        $patch = [
            'keywords/$draft' => null,
            'keywords/$seen' => true,
        ];
        if ($draftsId !== null) {
            $patch['mailboxIds/' . $draftsId] = null;
        }
        if ($sentId !== null) {
            $patch['mailboxIds/' . $sentId] = true;
        }
        return $patch;
    }

    /** @return array{drafts:?string, sent:?string} */
    private function resolveMailboxes(string $accountId): array
    {
        $result = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
        $drafts = null;
        $sent = null;
        foreach ($result['data']['list'] ?? [] as $mb) {
            $role = $mb['role'] ?? '';
            if ($role === 'drafts') $drafts = $mb['id'];
            if ($role === 'sent') $sent = $mb['id'];
        }

        // Self-healing: create missing standard mailboxes so the sent-copy
        // patch below always targets a REAL mailbox id (a literal 'sent'
        // string is not a valid id and silently fails).
        $missing = [];
        if ($drafts === null) $missing['mb_drafts'] = ['name' => 'Drafts', 'role' => 'drafts'];
        if ($sent === null) $missing['mb_sent'] = ['name' => 'Sent', 'role' => 'sent'];
        if ($missing !== []) {
            $create = $this->jmap->singleCall('Mailbox/set', [
                'accountId' => $accountId,
                'create' => $missing,
            ]);
            if (isset($create['error'])) {
                $this->logger->warning(
                    'Souvera Mail: failed to create missing standard mailboxes: ' . $create['error'],
                    ['app' => 'souvera_mail']
                );
            } else {
                foreach ($create['data']['created'] ?? [] as $key => $mb) {
                    $role = \str_starts_with((string) $key, 'mb_') ? \substr((string) $key, 3) : '';
                    if ($role === 'drafts' && $drafts === null) {
                        $drafts = $mb['id'] ?? null;
                    }
                    if ($role === 'sent' && $sent === null) {
                        $sent = $mb['id'] ?? null;
                    }
                }
            }
        }
        if ($sent === null) {
            $this->logger->warning(
                'Souvera Mail: no Sent mailbox id resolvable for account ' . $accountId . ' — sent copy will be skipped',
                ['app' => 'souvera_mail']
            );
        }
        return ['drafts' => $drafts, 'sent' => $sent];
    }

    private function uploadBlob(string $accountId, string $data, string $type, string $name): ?array
    {
        // Path-style Upload-URL statt Blob/upload-Methodenaufruf — siehe
        // V2JmapProxy::uploadBlob (Stalwart akzeptiert nur diese Form).
        $uploaded = $this->jmap->uploadBlob($accountId, $data, $type);
        if ($uploaded === null) {
            return null;
        }

        return [
            'blobId' => $uploaded['blobId'],
            'type' => $type,
            'name' => $name,
            'size' => $uploaded['size'],
        ];
    }
}
