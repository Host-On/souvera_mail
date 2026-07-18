<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\ExternalAccountsConfig;
use OCA\SouveraMail\Service\ExternalAccountsProviderPresets;
use OCA\SouveraMail\Service\LogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only surface consumed by the Vue front-end for the external-
 * mail-account feature.
 *
 * All heavy lifting (add / delete / switch / list mail) is done by the
 * Snappymail engine's own JSON endpoints — this controller only
 * exposes the Nextcloud-side glue:
 *
 *  - GET  /external/status     Is the feature on for this user?
 *  - GET  /external/preset     Lookup provider preset by email.
 *  - GET  /external/providers  Full provider directory (for the picker).
 *  - POST /external/consent    Store per-account GDPR consent flag.
 */
final class ExternalAccountsController extends Controller
{
    private const APP = 'souvera_mail';
    private const CONSENT_KEY_PREFIX = 'ext_consent.';

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IAppConfig $appConfig,
        private ExternalAccountsConfig $config,
        private LogService $log,
    ) {
        parent::__construct(self::APP, $request);
    }

    /**
     * GET /apps/souvera_mail/external/status
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function status(): JSONResponse
    {
        $user = $this->userSession->getUser();
        $uid  = $user ? $user->getUID() : '';

        $enabled = $this->config->isEnabled();
        $allowed = $uid !== '' ? $this->config->isAllowedForUser($uid) : false;
        $max     = $this->config->getMaxAccountsPerUser();

        return new JSONResponse([
            'ok'                => true,
            'enabled'           => $enabled,
            'allowed_for_me'    => $allowed,
            'max_per_user'      => $max,
            'consent_required'  => $this->config->isConsentRequired(),
            'consent_given'     => $uid !== '' ? $this->hasGlobalConsent($uid) : false,
            'migration_handoff' => $this->config->isMigrationHandoffEnabled(),
        ]);
    }

    /**
     * GET /apps/souvera_mail/external/preset?email=…
     *
     * Returns the local preset if we know it, otherwise `{ok:true,
     * preset:null}` — the frontend then falls back to letting
     * Snappymail's engine try online autoconfig.
     */
    #[NoAdminRequired]
    public function preset(string $email = ''): JSONResponse
    {
        $email = \trim($email);
        if ($email === '' || \strpos($email, '@') === false) {
            return new JSONResponse([
                'ok' => false,
                'error' => 'Missing or invalid email',
            ], Http::STATUS_BAD_REQUEST);
        }
        // Rate-limiting note: this endpoint is idempotent read-only
        // and returns purely static data; Nextcloud's default request
        // throttling is sufficient.
        return new JSONResponse([
            'ok'     => true,
            'preset' => ExternalAccountsProviderPresets::forEmail($email),
        ]);
    }

    /**
     * GET /apps/souvera_mail/external/providers
     *
     * Compact `{ domain: display_name }` map used by the picker.
     */
    #[NoAdminRequired]
    public function providers(): JSONResponse
    {
        return new JSONResponse([
            'ok'        => true,
            'providers' => ExternalAccountsProviderPresets::directory(),
        ]);
    }

    /**
     * POST /apps/souvera_mail/external/consent
     * Body: { email: "user@web.de" }
     *
     * Stores a boolean flag per (uid, email) so the frontend does not
     * have to show the GDPR modal again for THIS specific account.
     * The flag is per-account by design (user choice 4c) — a user
     * who adds a second web.de account will still see the modal.
     */
    #[NoAdminRequired]
    public function recordConsent(string $email = ''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse([
                'ok' => false,
                'error' => 'Not authenticated',
            ], Http::STATUS_UNAUTHORIZED);
        }
        $email = \strtolower(\trim($email));
        if ($email === '' || \strpos($email, '@') === false) {
            return new JSONResponse([
                'ok' => false,
                'error' => 'Missing or invalid email',
            ], Http::STATUS_BAD_REQUEST);
        }
        $uid = $user->getUID();
        $hash = \substr(\sha1($email), 0, 12);
        $key = self::CONSENT_KEY_PREFIX . $uid . '.' . $hash;
        $this->appConfig->setValueString(self::APP, $key, (string) \time());

        $this->log->info(\sprintf(
            'External account: GDPR consent recorded uid=%s email_hash=%s',
            $uid, $hash
        ), ['category' => 'external_accounts']);

        return new JSONResponse(['ok' => true]);
    }

    /** Check if the user has already consented for the "global" onboarding
     *  banner (as opposed to a specific email). Currently unused but
     *  kept so the frontend can lazy-check on load. */
    private function hasGlobalConsent(string $uid): bool
    {
        return $this->appConfig->hasKey(
            self::APP,
            self::CONSENT_KEY_PREFIX . $uid . '._global'
        );
    }
}
