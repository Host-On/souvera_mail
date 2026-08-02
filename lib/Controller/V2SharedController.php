<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class V2SharedController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwartAdmin,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/shared
     *
     * Returns shared mailboxes from the JMAP session's `accounts` map.
     * The user's own account is excluded from the result.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $bearer = $this->userContext->resolveBearer($user->getUID());
            $session = $this->stalwartAdmin->fetchSessionAsUser($bearer);

            if (($session['status'] ?? 500) !== 200) {
                return new JSONResponse(['error' => 'Session fetch failed'], 500);
            }

            $body = $session['body'];
            $ownAccountId = $body['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? '';

            // Parsed from the application configuration (stored via souvera_mail UI).
            $sharedAbove = $this->config->getUserValue(
                $user->getUID(), 'souvera_mail', 'shared_folders_position', 'below'
            );

            $accounts = \is_array($body['accounts'] ?? null) ? $body['accounts'] : [];
            $shared = [];

            foreach ($accounts as $accountId => $info) {
                if ($accountId === $ownAccountId) continue;
                $shared[] = [
                    'id' => $accountId,
                    'name' => $info['name'] ?? $accountId,
                    'isPersonal' => ($info['isPersonal'] ?? false) === true,
                    'isReadOnly' => ($info['isReadOnly'] ?? false) === true,
                    'hasDataFor' => $info['hasDataFor'] ?? [],
                ];
            }

            return new JSONResponse([
                'shared' => $shared,
                'position' => $sharedAbove,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage(), 'shared' => [], 'position' => 'below'], 500);
        }
    }

    /**
     * PUT /apps/souvera_mail/api/v2/shared/position
     * { position: "above" | "below" }
     */
    #[NoAdminRequired]
    public function setPosition(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $position = \trim((string) ($body['position'] ?? 'below'));

        if (!\in_array($position, ['above', 'below'], true)) {
            return new JSONResponse(['error' => 'Position must be "above" or "below"'], 400);
        }

        $this->config->setUserValue($user->getUID(), 'souvera_mail', 'shared_folders_position', $position);
        return new JSONResponse(['success' => true, 'position' => $position]);
    }
}
