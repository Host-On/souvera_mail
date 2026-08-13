<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\StalwartUserContext;
use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
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
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/identities
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        $aliases = $this->resolveAliases();
        $knownEmails = \array_map(fn($i) => \strtolower((string) $i['email']), $identities);
        foreach ($aliases as $alias) {
            if (\in_array(\strtolower($alias), $knownEmails, true)) continue;
            $identities[] = [
                'id' => 'alias:' . $alias,
                'name' => '',
                'email' => $alias,
                'isAlias' => true,
            ];
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
     * POST /apps/souvera_mail/api/v2/send
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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

        // Alias identities (id "alias:foo@bar.com") are not JMAP identities —
        // the FROM header gets the alias address while the submission uses
        // the primary JMAP identity.
        $fromEmail = $userEmail;
        if ($identityId !== null && \str_starts_with((string) $identityId, 'alias:')) {
            $fromEmail = \substr((string) $identityId, 6);
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
            $inReplyTo, $references, $draftsId
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
                'error' => 'Email creation failed',
                'detail' => $submitFailed !== null ? $submitFailed : ($emailResp['args'] ?? []),
            ], 500);
        }
        // If the submission succeeded the mail IS sent — report success even
        // when the intermediate draft create did not report a created id
        // (the implicit onSuccessUpdateEmail Email/set response has none).
        return new JSONResponse([
            'success' => true,
            'draftId' => $created['id'] ?? '',
            'submitted' => $submitted !== null,
        ]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/drafts
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
        return new JSONResponse([
            'success' => true,
            'draftId' => $created['id'] ?? '',
        ]);
    }

    /**
     * PUT /apps/souvera_mail/api/v2/drafts/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
    #[NoCSRFRequired]
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
    ): array {
        $emailObj = [
            'subject' => $subject,
            'from' => [['email' => $userEmail]],
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
        $base64 = \base64_encode($data);
        $result = $this->jmap->singleCall('Blob/upload', [
            'accountId' => $accountId,
            'create' => ['b1' => [
                'data:asBase64' => $base64,
                'type' => $type,
            ]],
        ]);

        if (isset($result['error'])) return null;

        $uploaded = $result['data']['created']['b1'] ?? null;
        if ($uploaded === null) return null;

        return [
            'blobId' => $uploaded['blobId'] ?? '',
            'type' => $type,
            'name' => $name,
            'size' => $uploaded['size'] ?? \strlen($data),
        ];
    }
}
