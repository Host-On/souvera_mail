<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class V2MailboxCrudController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $name = \trim((string) ($body['name'] ?? ''));
        $parentId = $body['parentId'] ?? null;

        if ($name === '') {
            return new JSONResponse(['error' => 'Name required'], 400);
        }

        $create = ['name' => $name];
        if ($parentId !== null && $parentId !== '') {
            $create['parentId'] = $parentId;
        }

        $result = $this->jmap->singleCall('Mailbox/set', [
            'accountId' => $accountId,
            'create' => ['new1' => $create],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        $created = $result['data']['created']['new1'] ?? null;
        if ($created === null) {
            return new JSONResponse(['error' => 'Creation failed'], 500);
        }

        return new JSONResponse(['mailbox' => [
            'id' => $created['id'] ?? '',
            'name' => $created['name'] ?? $name,
            'role' => $created['role'] ?? null,
            'parentId' => $created['parentId'] ?? null,
        ]]);
    }

    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $update = [];
        if (isset($body['name'])) {
            $update['name'] = \trim((string) $body['name']);
        }
        if (isset($body['parentId'])) {
            $update['parentId'] = \trim((string) $body['parentId']);
        }

        if ($update === []) {
            return new JSONResponse(['error' => 'No update fields'], 400);
        }

        $result = $this->jmap->call([
            ['Mailbox/set', [
                'accountId' => $accountId,
                'update' => [$id => $update],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        // Check for notUpdated.
        foreach ($result['responses'] ?? [] as $resp) {
            if (isset($resp['args']['notUpdated'])) {
                return new JSONResponse(['error' => 'Update rejected', 'detail' => $resp['args']['notUpdated']], 500);
            }
        }

        return new JSONResponse(['success' => true]);
    }

    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->call([
            ['Mailbox/set', [
                'accountId' => $accountId,
                'destroy' => [$id],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        // Check for notDestroyed.
        foreach ($result['responses'] ?? [] as $resp) {
            if (isset($resp['args']['notDestroyed'])) {
                return new JSONResponse(['error' => 'Destroy failed', 'detail' => $resp['args']['notDestroyed']], 500);
            }
        }

        return new JSONResponse(['success' => true]);
    }

}
