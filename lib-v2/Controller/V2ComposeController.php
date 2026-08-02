<?php

declare(strict_types=1);

namespace OCA\SouveraMail\V2\Controller;

use OCA\SouveraMail\V2\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Compose/Send API for the v2 Vue-3 frontend.
 *
 * Sends emails via Stalwart JMAP: Email/set (create draft) +
 * EmailSubmission/set (submit). Handles Blob/upload for attachments.
 */
class V2ComposeController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /apps/souvera_mail/api/v2/send
     *
     * Body JSON: { to, cc, bcc, subject, bodyHtml, bodyPlain, attachments: [{name, type, data(base64)}] }
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
            if (isset($upload['blobId'])) {
                $blobIds[] = $upload;
            }
        }

        // Build Email/create object.
        $emailObj = [
            'mailboxIds' => (object) ['d' => true], // drafts mailbox
            'subject' => $subject,
            'from' => [['email' => $accountId]],
            'to' => \array_map(fn($e) => ['email' => \trim($e)], $toAddr),
        ];

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

        // Create draft.
        $draftResult = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'create' => ['draft1' => $emailObj],
        ]);

        if (isset($draftResult['error'])) {
            return new JSONResponse($draftResult, 500);
        }

        $created = $draftResult['data']['created']['draft1'] ?? null;
        if ($created === null || !isset($created['id'])) {
            return new JSONResponse(['error' => 'Draft creation failed', 'raw' => $draftResult], 500);
        }

        $draftId = $created['id'];

        // Submit.
        $submitResult = $this->jmap->singleCall('EmailSubmission/set', [
            'accountId' => $accountId,
            'create' => ['send1' => [
                'emailId' => $draftId,
                'identityId' => $accountId,
            ]],
        ]);

        if (isset($submitResult['error'])) {
            return new JSONResponse(['error' => 'Submission failed', 'draftId' => $draftId, 'detail' => $submitResult['error']], 500);
        }

        $submitted = $submitResult['data']['created']['send1'] ?? null;
        return new JSONResponse([
            'success' => true,
            'draftId' => $draftId,
            'submitted' => $submitted !== null,
        ]);
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

        if (isset($result['error'])) {
            return null;
        }

        $uploaded = $result['data']['created']['b1'] ?? null;
        if ($uploaded === null) {
            return null;
        }

        return [
            'blobId' => $uploaded['blobId'] ?? '',
            'type' => $type,
            'name' => $name,
            'size' => $uploaded['size'] ?? \strlen($data),
        ];
    }
}
