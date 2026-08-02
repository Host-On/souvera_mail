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

class V2ComposeController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
        private StalwartUserContext $userContext,
    ) {
        parent::__construct($appName, $request);
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

        if ($toAddr === [] && $ccAddr === [] && $bccAddr === []) {
            return new JSONResponse(['error' => 'No recipients'], 400);
        }

        // Resolve user's email address (not accountId — accountId is base32).
        $user = $this->userSession->getUser();
        $userEmail = $this->userContext->resolveEmail($user->getUID());

        // Resolve identity via JMAP Identity/get.
        $identityId = $this->resolveIdentityId($accountId);
        if ($identityId === null) {
            return new JSONResponse(['error' => 'No JMAP identity found for this account'], 500);
        }

        // Resolve Drafts mailbox.
        $draftsId = $this->resolveMailboxId($accountId, 'drafts');
        if ($draftsId === null) {
            $draftsId = $this->resolveMailboxId($accountId, 'sent');
        }

        // Upload attachments as blobs.
        $blobIds = [];
        foreach ($attachments as $index => $att) {
            $name = $att['name'] ?? "attachment_{$index}.bin";
            $type = $att['type'] ?? 'application/octet-stream';
            $rawData = \base64_decode((string) ($att['data'] ?? ''), true);
            if ($rawData === false || $rawData === '') {
                continue;
            }
            $upload = $this->uploadBlob($accountId, $rawData, $type, $name);
            if ($upload !== null) {
                $blobIds[] = $upload;
            }
        }

        // Build Email/create object.
        $emailObj = [
            'subject' => $subject,
            'from' => [['email' => $userEmail]],
            'to' => \array_map(fn($e) => ['email' => \trim($e)], $toAddr),
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

        if ($blobIds !== []) {
            $emailObj['attachments'] = \array_map(fn($b) => [
                'blobId' => $b['blobId'],
                'type' => $b['type'],
                'name' => $b['name'],
                'size' => $b['size'] ?? 0,
            ], $blobIds);
        }

        // Create + submit in one batch.
        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'create' => ['draft1' => $emailObj],
            ]],
            ['EmailSubmission/set', [
                'accountId' => $accountId,
                'onSuccessCreateEmail' => ['#c' . ($this->jmapCallNumForSubmission() + 0) => 'send1'],
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

        // Check Email/set result.
        $emailResp = null;
        $submissionResp = null;
        foreach ($responses as $resp) {
            if ($resp['name'] === 'Email/set') $emailResp = $resp;
            if ($resp['name'] === 'EmailSubmission/set') $submissionResp = $resp;
        }

        $created = $emailResp['args']['created']['draft1'] ?? null;
        if ($created === null) {
            return new JSONResponse(['error' => 'Email creation failed', 'detail' => $emailResp['args'] ?? []], 500);
        }

        $submitted = $submissionResp['args']['created']['send1'] ?? null;
        return new JSONResponse([
            'success' => true,
            'draftId' => $created['id'] ?? '',
            'submitted' => $submitted !== null,
        ]);
    }

    private function resolveIdentityId(string $accountId): ?string
    {
        $result = $this->jmap->singleCall('Identity/get', ['accountId' => $accountId]);
        $list = $result['data']['list'] ?? [];
        if (\count($list) > 0) {
            return $list[0]['id'] ?? null;
        }
        return $accountId; // fallback: use accountId as identityId
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

    /** Called before singleCall so it knows the next callId counter value. */
    private function jmapCallNumForSubmission(): int
    {
        // Reflect the callId used in the first call of the batch.
        // Not ideal, but we just need the relative index.
        return 0;
    }
}
