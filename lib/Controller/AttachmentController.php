<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class AttachmentController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /apps/souvera_mail/api/v2/attachments/{blobId}/save
     * { name, accountId? }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveToFiles(string $blobId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $name = (string) ($body['name'] ?? 'attachment');
        $targetPath = \trim((string) ($body['targetPath'] ?? ''), '/');
        $accountId = $body['accountId'] ?? null;

        if (empty($accountId)) {
            $accountId = $this->jmap->getCurrentAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $result = $this->jmap->singleCall('Blob/get', [
                'accountId' => $accountId,
                'ids' => [$blobId],
            ]);

            if (isset($result['error'])) {
                return new JSONResponse(['error' => 'Blob not found'], 404);
            }

            $blob = $result['data']['list'][0] ?? null;
            if ($blob === null) {
                return new JSONResponse(['error' => 'Blob not found'], 404);
            }

            $data = \base64_decode($blob['data:asBase64'] ?? '', true);
            if ($data === false || $data === '') {
                $data = $blob['data:asText'] ?? $blob['data'] ?? '';
            }
            if ($data === '') {
                return new JSONResponse(['error' => 'Empty blob'], 500);
            }

            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $targetFolder = $userFolder;
            if ($targetPath !== '') {
                $targetFolder = $userFolder->nodeExists($targetPath)
                    ? $userFolder->get($targetPath)
                    : $userFolder->newFolder($targetPath);
            }
            $safeName = $this->safeFileName($name, $targetFolder);
            $file = $targetFolder->newFile($safeName, $data);

            return new JSONResponse([
                'success' => true,
                'path' => $userFolder->getRelativePath($file->getPath()),
                'name' => $safeName,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/attachments/save-all
     * { blobIds: [{blobId, name}], accountId? }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveAll(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $attachments = $body['attachments'] ?? [];
        $targetPath = \trim((string) ($body['targetPath'] ?? ''), '/');
        $accountId = $body['accountId'] ?? null;

        if (empty($accountId)) {
            $accountId = $this->jmap->getCurrentAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $saved = [];
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $targetFolder = $userFolder;
        if ($targetPath !== '') {
            $targetFolder = $userFolder->nodeExists($targetPath)
                ? $userFolder->get($targetPath)
                : $userFolder->newFolder($targetPath);
        }

        foreach ($attachments as $att) {
            $blobId = $att['blobId'] ?? '';
            $name = (string) ($att['name'] ?? 'attachment');
            if ($blobId === '') continue;

            try {
                $result = $this->jmap->singleCall('Blob/get', [
                    'accountId' => $accountId,
                    'ids' => [$blobId],
                ]);
                $blob = $result['data']['list'][0] ?? null;
                if ($blob === null) continue;

                $data = \base64_decode($blob['data:asBase64'] ?? '', true);
                if ($data === false || $data === '') {
                    $data = $blob['data:asText'] ?? $blob['data'] ?? '';
                }
                if ($data === '') continue;

                $safeName = $this->safeFileName($name, $targetFolder);
                $file = $targetFolder->newFile($safeName, $data);
                $saved[] = ['name' => $safeName, 'path' => $userFolder->getRelativePath($file->getPath())];
            } catch (\Throwable) {
                continue;
            }
        }

        return new JSONResponse(['success' => true, 'saved' => $saved]);
    }

    private function safeFileName(string $name, \OCP\Files\Folder $folder): string
    {
        $name = \preg_replace('/[<>:"\/\\\\|?*]/', '_', $name);
        if ($name === '') $name = 'attachment';

        $base = \pathinfo($name, \PATHINFO_FILENAME);
        $ext = \pathinfo($name, \PATHINFO_EXTENSION);
        $candidate = $ext !== '' ? $base . '.' . $ext : $base;
        $counter = 1;

        while ($folder->nodeExists($candidate)) {
            $candidate = $ext !== ''
                ? $base . ' (' . $counter . ').' . $ext
                : $base . ' (' . $counter . ')';
            $counter++;
        }

        return $candidate;
    }
}
