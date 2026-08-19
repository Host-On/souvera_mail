<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

class FilesController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/files/list?path=/
     * Returns a flat list of files + folders in the given directory.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(string $path = ''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            if ($path !== '' && $path !== '/') {
                $node = $userFolder->get($path);
                if ($node instanceof \OCP\Files\Folder) {
                    $folder = $node;
                } else {
                    return new JSONResponse(['error' => 'Not a directory'], 400);
                }
            } else {
                $folder = $userFolder;
            }

            $items = [];
            foreach ($folder->getDirectoryListing() as $node) {
                $items[] = [
                    'name' => $node->getName(),
                    'type' => $node->getType(),
                    'size' => $node instanceof File ? $node->getSize() : 0,
                    'mtime' => $node->getMTime(),
                    'mimetype' => $node->getMimetype(),
                ];
            }

            // Sort: folders first, then alphabetically
            \usort($items, function ($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'dir' ? -1 : 1;
                }
                return \strcasecmp($a['name'], $b['name']);
            });

            return new JSONResponse([
                'files' => $items,
                'path' => $path ?: '/',
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/files/attach
     * Downloads a file from NC Files, uploads it as a JMAP blob.
     * { filePath, accountId? } → { blobId, name, type, size }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function attach(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $filePath = (string) ($body['filePath'] ?? '');
        $accountId = $body['accountId'] ?? null;

        if ($filePath === '') {
            return new JSONResponse(['error' => 'filePath required'], 400);
        }
        if (empty($accountId)) {
            $accountId = $this->jmap->getCurrentAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $node = $userFolder->get($filePath);

            if (!($node instanceof File)) {
                return new JSONResponse(['error' => 'Not a file'], 400);
            }

            $data = $node->getContent();
            $name = $node->getName();
            $type = $node->getMimetype();
            $size = $node->getSize();

            $base64 = \base64_encode($data);
            $result = $this->jmap->singleCall('Blob/upload', [
                'accountId' => $accountId,
                'create' => ['b1' => [
                    'data:asBase64' => $base64,
                    'type' => $type,
                ]],
            ]);

            if (isset($result['error'])) {
                return new JSONResponse(['error' => 'Blob upload failed'], 500);
            }

            $uploaded = $result['data']['created']['b1'] ?? null;
            if ($uploaded === null) {
                return new JSONResponse(['error' => 'Blob upload failed'], 500);
            }

            return new JSONResponse([
                'success' => true,
                'blobId' => $uploaded['blobId'] ?? '',
                'name' => $name,
                'type' => $type,
                'size' => $size,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
