<?php

class NextcloudPlugin extends \Smail\Engine\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		VERSION = '2.39.0',
		RELEASE  = '2024-10-08',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			\Smail\Engine\Log::debug('Nextcloud', 'integrated');
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('filter.language', 'FilterLanguage');

			$this->addCss('style.css');

			$this->addJs('js/webdav.js');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');

			$this->addJs('js/composer.js');
			$this->addJsonHook('NextcloudAttachFile', 'NextcloudAttachFile');

			$this->addJs('js/messagelist.js');

			$this->addJs('js/quota.js');
			$this->addJs('js/settings-account.js');
			$this->addJs('js/help-modal.js');
			$this->addJs('js/folder-names.js');

			$this->addCss('css/help-modal.css');

			$this->addTemplate('templates/PopupsNextcloudFiles.html');
			$this->addTemplate('templates/PopupsNextcloudCalendars.html');
			$this->addTemplate('templates/SettingsSouveraAccount.html');

			$this->addHook('imap.before-login', 'beforeLogin');
			$this->addHook('smtp.before-login', 'beforeLogin');
			$this->addHook('sieve.before-login', 'beforeLogin');
		} else {
			\Smail\Engine\Log::debug('Nextcloud', 'NOT integrated');
			// \OC::$server->getConfig()->getAppValue('souvera_mail', 'smail-no-embed');
			$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
		}
	}

	public function ContentSecurityPolicy(\Smail\Engine\HTTP\CSP $CSP)
	{
		if (\method_exists($CSP, 'add')) {
			$CSP->add('frame-ancestors', "'self'");
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OCP\Server::get(\OCP\IUserSession::class)->isLoggedIn();
	}

	public function beforeLogin(\Smail\Engine\Model\Account $oAccount, \Smail\Mail\Net\NetClient $oClient, \Smail\Mail\Net\ConnectSettings $oSettings) : void
	{
		// Swap the `oidc_login|<uid>` sentinel for a live H2CK/oidc access
		// token on every IMAP / SMTP / Sieve connect — including connects
		// fired *without* an active NC session (background dashboard widget
		// refresh, cron jobs, engine-token-cookie reconnects, Sieve from
		// CLI). The sentinel itself is the authoritative source of identity
		// here because the engine persists it in the account record at first
		// login (see Smail\Engine\Actions\UserAuth::accountFromNcSession()).
		// We must NOT gate on isOIDCLogin() — that returns false when no NC
		// user is currently logged in, the sentinel then leaks to Stalwart
		// as a plaintext password and the mail server rejects the connect
		// with AUTHENTICATIONFAILED.
		if (!($oAccount instanceof \Smail\Engine\Model\MainAccount)) {
			return;
		}
		if (!\str_starts_with($oSettings->passphrase, 'oidc_login|')) {
			return;
		}

		$sUid = \substr($oSettings->passphrase, \strlen('oidc_login|'));
		if ($sUid === '') {
			// Malformed sentinel — leave passphrase untouched so the
			// engine falls through to its normal error path instead of
			// us silently masking the bad account record.
			return;
		}

		$helper = \OCP\Server::get(\OCA\SouveraMail\Util\EngineHelper::class);
		$sToken = $helper->getOidcAccessTokenForUid($sUid);
		if (!$sToken) {
			// H2CK refused or autologin-oidc disabled — leave the sentinel
			// in place. The engine's normal IMAP error handling kicks in
			// and the LoggerInterface call inside OidcProviderService
			// already wrote a structured warning for the admin.
			return;
		}

		$oSettings->passphrase = $sToken;
		$oSettings->SASLMechanisms = \array_values(\array_unique(
			\array_merge(array('OAUTHBEARER'), $oSettings->SASLMechanisms)
		));
	}

	/*
	\OC::$server->getCalendarManager();
	\OC::$server->getLDAPProvider();
	*/

	private static function getUserFolder(): ?\OCP\Files\Folder
	{
		$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		if (!$user) {
			return null;
		}
		return \OCP\Server::get(\OCP\Files\IRootFolder::class)
			->getUserFolder($user->getUID());
	}

	public function NextcloudAttachFile() : array
	{
		$aResult = [
			'success' => false,
			'tempName' => ''
		];
		$sFile = $this->jsonParam('file', '');
		if (\str_contains($sFile, '..') || \str_contains($sFile, "\0")) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}
		$userFolder = static::getUserFolder();
		if ($userFolder && $userFolder->nodeExists($sFile)) {
			$node = $userFolder->get($sFile);
			if ($node instanceof \OCP\Files\File && $fp = $node->fopen('rb')) {
				$oActions = \Smail\Engine\Api::Actions();
				$oAccount = $oActions->getAccountFromToken();
				if ($oAccount) {
					$sSavedName = 'nextcloud-file-' . \sha1($sFile . \microtime());
					if (!$oActions->FilesProvider()->PutFile($oAccount, $sSavedName, $fp)) {
						$aResult['error'] = 'failed';
					} else {
						$aResult['tempName'] = $sSavedName;
						$aResult['success'] = true;
					}
				}
			}
		}
		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function NextcloudSaveMsg() : array
	{
		$sSaveFolder = \ltrim($this->jsonParam('folder', ''), '/');
//		$aValues = \Smail\Engine\Api::Actions()->decodeRawKey($this->jsonParam('msgHash', ''));
		$msgHash = $this->jsonParam('msgHash', '');
		$aValues = \json_decode(\Smail\Mail\Base\Utils::UrlSafeBase64Decode($msgHash), true);
		$aResult = [
			'folder' => '',
			'filename' => '',
			'success' => false
		];
		if (\str_contains($sSaveFolder, '..') || \str_contains($sSaveFolder, "\0")) {
			return $this->jsonResponse(__FUNCTION__, $aResult);
		}
		if ($sSaveFolder && !empty($aValues['folder']) && !empty($aValues['uid'])) {
			$oActions = \Smail\Engine\Api::Actions();
			$oMailClient = $oActions->MailClient();
			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
			}

			$sSaveFolder = $sSaveFolder ?: 'Emails';
			$userFolder = static::getUserFolder();
			$saveFolder = $userFolder?->getOrCreateFolder($sSaveFolder);
			$aResult['folder'] = $sSaveFolder;
			$aResult['filename'] = \Smail\Mail\Base\Utils::SecureFileName(
				\mb_substr($this->jsonParam('filename', '') ?: \date('YmdHis'), 0, 100)
			) . '.' . \md5($msgHash) . '.eml';

			$oMailClient->MessageMimeStream(
				function ($rResource) use ($saveFolder, &$aResult) {
					if (\is_resource($rResource) && $saveFolder) {
						$saveFolder->newFile($aResult['filename'], $rResource);
						$aResult['success'] = true;
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	public function DoAttachmentsActions(\Smail\Engine\AttachmentsAction $data)
	{
		if (static::isLoggedIn() && 'nextcloud' === $data->action) {
			$userFolder = static::getUserFolder();
			if ($userFolder) {
				$sSaveFolder = \ltrim($this->jsonParam('NcFolder', ''), '/');
				if (\str_contains($sSaveFolder, '..') || \str_contains($sSaveFolder, "\0")) {
					return;
				}
				$sSaveFolder = $sSaveFolder ?: 'Attachments';
				$saveFolder = $userFolder->getOrCreateFolder($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = empty($aItem['fileName']) ? 'file.dat' : $aItem['fileName'];
					$sUniqueName = $saveFolder->getNonExistingName($sSavedFileName);
					if (!empty($aItem['data'])) {
						$saveFolder->newFile($sUniqueName, $aItem['data']);
					} else if (!empty($aItem['fileHash'])) {
						$fFile = $data->filesProvider->GetFile($data->account, $aItem['fileHash'], 'rb');
						if (\is_resource($fFile)) {
							$saveFolder->newFile($sUniqueName, $fFile);
							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult)) {
			$ocUser = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
			$sUID = $ocUser->getUID();
			$oUrlGen = \OCP\Server::get(\OCP\IURLGenerator::class);
			$sWebDAV = $oUrlGen->getAbsoluteURL($oUrlGen->linkTo('', 'remote.php') . '/dav');

			// Disable the engine's inactivity-driven auto-logout in SSO
			// mode. NC's session is the authoritative auth anchor (its
			// own session_lifetime + sliding-window logic already enforce
			// idle expiry centrally); the engine's 30-minute default just
			// races with it and surfaces the chaotic "Logout Error →
			// AuthError[102] on next Folders refresh" sequence the
			// operator reported on 2026-07-01.
			//
			// Operators who want a stricter idle policy ON TOP of NC's
			// session lifetime can override via:
			//   occ config:app:set souvera_mail engine_autologout_minutes --value 60
			// Default is 0 (engine inactivity timer disabled — NC owns auth).
			$autoLogout = (int) \OCP\Server::get(\OCP\IConfig::class)
				->getAppValue('souvera_mail', 'engine_autologout_minutes', '0');
			$aResult['AutoLogout'] = $autoLogout;
			// Seed a default mail identity using the NC display name on the
			// very first request after first login — saves the user from the
			// "please enter your name in Settings → Identities" gating dialog
			// the engine shows when no identity exists yet. No-op on every
			// subsequent request (already-set identity is preserved verbatim).
			$this->seedDefaultIdentityFromNcProfile($ocUser);

			// Reconcile Stalwart-managed shared-mailbox identities with the
			// engine's local identity storage. Throttled to once per 15 min
			// per user inside the service — 99% of boots are a microsecond
			// cache hit. See {@see SharedIdentitySyncService::syncIfStale}.
			$this->syncStalwartIdentitiesIfStale($ocUser);
//			$sWebDAV = \OCP\Util::linkToRemote('dav');
			$aResult['Nextcloud'] = [
				'UID' => $sUID,
				'WebDAV' => $sWebDAV,
				'CalDAV' => $this->Config()->Get('plugin', 'calendar', false),
				// URL of the smail mailbox-quota JSON endpoint — consumed by
				// app/plugins/nextcloud/js/quota.js to render the live quota
				// pill in the engine UI. Always emitted; the JS gates on the
				// fetch response (gracefully hides if 503 / endpoint missing).
				'SmailQuotaUrl' => $oUrlGen->getAbsoluteURL($oUrlGen->linkToRoute('souvera_mail.quota.index')),
				// URLs consumed by app/plugins/nextcloud/js/settings-account.js —
				// the in-engine "Sicherheit & Geräte" Settings tab (Snappymail-
				// native Knockout ViewModel registered via rl.addSettingsViewModel).
				// Destroy URLs are built via index-URL + '/__ID__' to avoid
				// Symfony's UrlGenerator validating the `\d+` requirement against
				// the literal '__ID__' placeholder.
				'SmailDashboardModeUrl' => $oUrlGen->linkToRoute('souvera_mail.preference.setDashboardMode'),
				'SmailDashboardMode' => $this->resolveDashboardModeForNextcloud($sUID),
				'SmailAppPasswordsListUrl' => $oUrlGen->linkToRoute('souvera_mail.appPassword.index'),
				'SmailAppPasswordsCreateUrl' => $oUrlGen->linkToRoute('souvera_mail.appPassword.create'),
				'SmailAppPasswordsDestroyUrlTemplate' => $oUrlGen->linkToRoute('souvera_mail.appPassword.index') . '/__ID__',
				'SmailAppPasswordsAvailable' => $this->isAppPasswordsAvailable(),
				'SmailConnectedDevicesListUrl' => $oUrlGen->linkToRoute('souvera_mail.connectedDevices.index'),
				'SmailConnectedDevicesDestroyUrlTemplate' => $oUrlGen->linkToRoute('souvera_mail.connectedDevices.index') . '/__ID__',
				'SmailConnectedDevicesSignOutOthersUrl' => $oUrlGen->linkToRoute('souvera_mail.connectedDevices.signOutOthers'),
				// Legacy entry-point retained for browser bookmarks pointing at
				// /apps/souvera_mail/settings — the controller now redirects to
				// the engine-internal hash route #/settings/souvera-account.
				'SmailSettingsUrl' => $oUrlGen->getAbsoluteURL($oUrlGen->linkToRoute('souvera_mail.settings.index'))
			] + $this->buildHelpData($sUID, $sWebDAV, $ocUser, $oUrlGen)
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			;
			if (empty($aResult['Auth'])) {
				$config = \OCP\Server::get(\OCP\IConfig::class);
				$sEmail = '';
				if ($config->getAppValue('souvera_mail', 'autologin', false)
					|| $config->getAppValue('souvera_mail', 'autologin-with-email', false)) {
					// Always use NC profile email, never bare UID
					$sEmail = $config->getUserValue($sUID, 'settings', 'email', '')
						?: $ocUser->getEMailAddress()
						?: $sUID;
				} else {
					\Smail\Engine\Log::debug('Nextcloud', 'autologin is off');
				}
				$sCustomEmail = $config->getUserValue($sUID, 'souvera_mail', 'email', '');
				if ($sCustomEmail) {
					$sEmail = $sCustomEmail;
				}
				if (!$sEmail) {
					$sEmail = $ocUser->getEMailAddress();
				}
/*
				if ($config->getAppValue('souvera_mail', 'autologin-oidc', false)) {
					if (\OC::$server->getSession()->get('is_oidc')) {
						$sEmail = "{$sUID}@nextcloud";
						$aResult['DevPassword'] = \OC::$server->getSession()->get('oidc_access_token');
					} else {
						\Smail\Engine\Log::debug('Nextcloud', 'Not an OIDC login');
					}
				} else {
					\Smail\Engine\Log::debug('Nextcloud', 'OIDC is off');
				}
*/
				$aResult['DevEmail'] = $sEmail ?: '';
			}
		}
	}

	public function FilterLanguage(&$sLanguage, $bAdmin) : void
	{
		if (!\Smail\Engine\Api::Config()->Get('webmail', 'allow_languages_on_settings', true)) {
			$aResultLang = \Smail\Engine\L10n::getLanguages($bAdmin);
			$userId = \OCP\Server::get(\OCP\IUserSession::class)->getUser()->getUID();
			$userLang = \OCP\Server::get(\OCP\IConfig::class)->getUserValue($userId, 'core', 'lang', 'en');
			$userLang = \strtr($userLang, '_', '-');
			$sLanguage = $this->determineLocale($userLang, $aResultLang);
			// Check if $sLanguage is null
			if (!$sLanguage) {
				$sLanguage = 'en'; // Assign 'en' if $sLanguage is null
			}
		}
	}

	/**
	 * Determine locale from user language.
	 *
	 * @param string $langCode The name of the input.
	 * @param array  $languagesArray The value of the array.
	 *
	 * @return string return locale
	 */
	private function determineLocale(string $langCode, array $languagesArray) : ?string
	{
		// Direct check for the language code
		if (\in_array($langCode, $languagesArray)) {
			return $langCode;
		}

		// Check without country code
		if (\str_contains($langCode, '-')) {
			$langCode = \explode('-', $langCode)[0];
			if (\in_array($langCode, $languagesArray)) {
				return $langCode;
			}
		}

		// Check with uppercase country code
		$langCodeWithUpperCase = $langCode . '-' . \strtoupper($langCode);
		if (\in_array($langCodeWithUpperCase, $languagesArray)) {
			return $langCodeWithUpperCase;
		}

		// If no match is found
		return null;
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('address-book' === $sName) {
				include_once __DIR__ . '/NextcloudAddressBook.php';
				$mResult = new \NextcloudAddressBook();
			} elseif ('filters' === $sName) {
				// Swap the engine's ManageSieve-port-4190 SieveStorage for
				// a JMAP-backed provider that reuses the H2CK/oidc JWT we
				// already issue for AppPasswords / Quota / Identity sync.
				// Bypasses the port-4190 dial-out chain that produced
				// engine notification 352 (`CantGetFilters`) on the
				// operator's Stalwart 0.16 deploy (PRD step 23 + 25).
				// Best-effort: any failure to wire the provider leaves
				// $mResult untouched so the engine falls back to its own
				// default. We never throw out of a fabrica hook —
				// crashes here take down the entire engine boot.
				try {
					if (\class_exists(\OCA\SouveraMail\Engine\Filters\JmapSieveStorage::class)
						&& \class_exists(\OCA\SouveraMail\Service\SieveScriptService::class)) {
						$svc = \OCP\Server::get(\OCA\SouveraMail\Service\SieveScriptService::class);
						if ($svc->isAvailable()) {
							$mResult = \OCP\Server::get(\OCA\SouveraMail\Engine\Filters\JmapSieveStorage::class);
						}
					}
				} catch (\Throwable $e) {
					\Smail\Engine\Log::warning(
						'Nextcloud',
						'JmapSieveStorage wiring skipped: ' . $e->getMessage()
					);
				}
			}
		}
	}

	protected function configMapping() : array
	{
		return array(
			\Smail\Engine\Plugins\Property::NewInstance('calendar')->SetLabel('Enable "Put ICS in calendar"')
				->SetType(\Smail\Engine\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
		);
	}

	/**
	 * Read the current Souvera Mail dashboard widget mode for the given NC
	 * user — emitted into the FilterAppData payload so the Snappymail-side
	 * Settings tab can render the radio group with the correct initial
	 * selection (defaults to 'unread' for parity with the widget itself).
	 */
	protected function resolveDashboardModeForNextcloud(string $sUID) : string
	{
		try {
			$config = \OCP\Server::get(\OCP\IConfig::class);
			$mode = $config->getUserValue($sUID, 'souvera_mail', 'dashboard-mode', 'unread');
			return ('all' === $mode) ? 'all' : 'unread';
		} catch (\Throwable $e) {
			return 'unread';
		}
	}

	/**
	 * True if the App-Passwords feature can be offered to the user. Matches
	 * the gating used by lib/Service/AppPasswordService::isAvailable() —
	 * Stalwart API URL configured by souvera_central AND H2CK/oidc present.
	 * Tested defensively so the Settings tab never crashes the engine boot
	 * just because souvera_central is missing.
	 */
	protected function isAppPasswordsAvailable() : bool
	{
		try {
			$svc = \OCP\Server::get(\OCA\SouveraMail\Service\AppPasswordService::class);
			return $svc->isAvailable();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * On the very first request after a fresh login, write a default mail
	 * identity using the Nextcloud user's profile display-name so the user
	 * is not prompted for it in the engine's compose UI ("Please set your
	 * name in Settings → Identities"). Cheap no-op on every subsequent
	 * request — already-stored identities are preserved verbatim (we never
	 * overwrite a user-edited identity).
	 *
	 * Best-effort: any exception is swallowed. Failing to seed is annoying
	 * but never breaks the boot. The engine's normal identity-empty path
	 * keeps working.
	 */
	protected function seedDefaultIdentityFromNcProfile(\OCP\IUser $ocUser) : void
	{
		try {
			$displayName = \trim((string) $ocUser->getDisplayName());
			if ($displayName === '') {
				return;
			}
			$actions = \Smail\Engine\Api::Actions();
			$account = $actions->getMainAccountFromToken(false);
			if (!$account) {
				return;
			}
			$email = $account->Email();
			if ($email === '') {
				return;
			}
			$storage = $actions->LocalStorageProvider();
			$type = \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value;
			$existingRaw = $storage->Get($account, $type, 'identities');
			$existing = \json_decode((string) $existingRaw, true);
			if (\is_array($existing) && !empty($existing)) {
				// User (or a previous seed) already has at least one identity — leave it alone.
				return;
			}

			// Shape mirrors Smail\Engine\Model\Identity::ToSimpleJSON().
			$identity = [
				'Id' => '',
				'Label' => '',
				'Email' => $email,
				'Name' => $displayName,
				'ReplyTo' => '',
				'Bcc' => '',
				'Signature' => '',
				'SignatureInsertBefore' => false,
				'sentFolder' => '',
				'pgpEncrypt' => false,
				'pgpSign' => false,
				'smimeKey' => '',
				'smimeCertificate' => '',
			];
			$storage->Put($account, $type, 'identities', \json_encode([$identity]));
			\Smail\Engine\Log::info('Nextcloud', 'seeded default identity for ' . $ocUser->getUID() . ' (' . $email . ')');
		} catch (\Throwable $e) {
			\Smail\Engine\Log::warning('Nextcloud', 'identity seed skipped: ' . $e->getMessage());
		}
	}

	/**
	 * Pulls the user's Stalwart-managed identities (own mailbox + every
	 * shared mailbox where they have send-as permission) and reconciles
	 * them into the engine's per-account `identities` blob. Idempotent +
	 * throttled to once per 15 min per user inside the service — every
	 * other engine boot is a microsecond cache hit. Manual identities
	 * (Id not prefixed `stalwart:`) are preserved verbatim; Stalwart-
	 * managed entries that no longer exist on the server are removed;
	 * display-name / email changes are picked up on the next sync window.
	 *
	 * Best-effort: any exception is swallowed at WARN level. A failed
	 * sync simply leaves the engine's identity list as-is.
	 */
	protected function syncStalwartIdentitiesIfStale(\OCP\IUser $ocUser) : void
	{
		try {
			if (!\class_exists(\OCA\SouveraMail\Service\SharedIdentitySyncService::class)) {
				return;
			}
			$svc = \OCP\Server::get(\OCA\SouveraMail\Service\SharedIdentitySyncService::class);
			if (!$svc->isAvailable()) {
				return;
			}
			$stalwart = $svc->syncIfStale($ocUser->getUID());
			if ($stalwart === null) {
				// Cache hit — engine's stored identities are still authoritative.
				return;
			}

			$actions = \Smail\Engine\Api::Actions();
			$account = $actions->getMainAccountFromToken(false);
			if (!$account) {
				return;
			}
			$storage = $actions->LocalStorageProvider();
			$type = \Smail\Engine\Providers\Storage\Enumerations\StorageType::CONFIG->value;

			$existingRaw = $storage->Get($account, $type, 'identities');
			$existing = \json_decode((string) $existingRaw, true);
			if (!\is_array($existing)) {
				$existing = [];
			}

			$reconciled = $svc->reconcile($existing, $stalwart);

			// Only write back when something actually changed — avoids
			// spurious LocalStorageProvider churn on the first run after
			// a fresh JMAP sync that happens to return the same payload.
			if (\json_encode($reconciled) !== \json_encode($existing)) {
				$storage->Put($account, $type, 'identities', \json_encode($reconciled));
				\Smail\Engine\Log::info(
					'Nextcloud',
					'reconciled ' . \count($stalwart) . ' Stalwart-managed identities for ' . $ocUser->getUID()
				);
			}
		} catch (\Throwable $e) {
			\Smail\Engine\Log::warning('Nextcloud', 'Stalwart identity sync skipped: ' . $e->getMessage());
		}
	}

	/**
	 * Build the read-only "Hilfe" payload consumed by the extended
	 * F1 help modal `PopupsKeyboardShortcutsHelp` (see
	 * `plugins/nextcloud/js/help-modal.js`).
	 *
	 * Every value is a string (never null / missing), so the JS side
	 * can render a friendly placeholder ("—") without null-checks. The
	 * IMAP/POP3/SMTP/Sieve rows are sourced from the active engine
	 * domain-config JSON via {@see DomainConfigService::readDomainConfig()}.
	 * POP3 is derived from the IMAP host with the well-known 995 / SSL
	 * default (Stalwart 0.16 ships POP3 on 995 alongside IMAP on 993 —
	 * see stalw.art/docs/server/listener#pop3).
	 *
	 * The Souvera Shield quarantine URL is optional and comes from the
	 * app-config key `souvera_mail.shield_url`; when empty the JS tab
	 * shows a friendly "not configured" banner with the operator
	 * override command.
	 *
	 * Any failure to read the domain config (e.g. fresh install with
	 * no domain profile yet) returns friendly placeholder strings —
	 * the tab NEVER crashes the settings screen.
	 *
	 * @return array<string, string>
	 */
	protected function buildHelpData(string $sUID, string $sWebDAV, \OCP\IUser $ocUser, \OCP\IURLGenerator $oUrlGen) : array
	{
		$domain = '';
		$imapHost = '';  $imapPort = '';  $imapSsl = '';
		$smtpHost = '';  $smtpPort = '';  $smtpSsl = '';
		$sieveHost = ''; $sievePort = ''; $sieveSsl = '';

		try {
			$domainService = \OCP\Server::get(\OCA\SouveraMail\Service\DomainConfigService::class);
			$domains = $domainService->listDomains();
			if (!empty($domains)) {
				$domain = (string) $domains[0];
				$cfg = $domainService->readDomainConfig($domain) ?: [];
				$imap = \is_array($cfg['IMAP'] ?? null) ? $cfg['IMAP'] : [];
				$smtp = \is_array($cfg['SMTP'] ?? null) ? $cfg['SMTP'] : [];
				$sieve = \is_array($cfg['Sieve'] ?? null) ? $cfg['Sieve'] : [];

				$imapHost = (string) ($imap['host'] ?? '');
				$imapPort = isset($imap['port']) ? (string) $imap['port'] : '';
				$imapSsl = \OCA\SouveraMail\Service\DomainConfigService::sslToString((int) ($imap['type'] ?? 0));

				$smtpHost = (string) ($smtp['host'] ?? '');
				$smtpPort = isset($smtp['port']) ? (string) $smtp['port'] : '';
				$smtpSsl = \OCA\SouveraMail\Service\DomainConfigService::sslToString((int) ($smtp['type'] ?? 0));

				if (!empty($sieve['enabled'])) {
					$sieveHost = (string) ($sieve['host'] ?? '');
					$sievePort = isset($sieve['port']) ? (string) $sieve['port'] : '';
					$sieveSsl = \OCA\SouveraMail\Service\DomainConfigService::sslToString((int) ($sieve['type'] ?? 0));
				}
			}
		} catch (\Throwable $e) {
			\Smail\Engine\Log::warning('Nextcloud', 'help data read skipped: ' . $e->getMessage());
		}

		// Souvera Shield URL — auto-resolves to the Nextcloud
		// `souvera_shield` app when it is installed AND enabled for
		// the current user. Falls back to an explicit operator
		// override in app-config `souvera_mail.shield_url` for
		// deployments where Shield lives on a different host.
		//
		// Empty string means "hide the entire Shield block" — end
		// users must never see raw `occ` commands (they don't have
		// shell access on managed Souvera clusters).
		$shieldUrl = '';
		try {
			$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
			if ($appManager->isEnabledForUser('souvera_shield', $ocUser)) {
				$shieldUrl = $oUrlGen->getAbsoluteURL(
					$oUrlGen->linkToRoute('souvera_shield.page.index')
				);
			}
		} catch (\Throwable $e) {
			// souvera_shield not installed or has no `page.index`
			// route — fall through to app-config override.
		}
		if ($shieldUrl === '') {
			try {
				$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
				$shieldUrl = (string) $appConfig->getValueString('souvera_mail', 'shield_url', '');
			} catch (\Throwable $e) {
				try {
					$config = \OCP\Server::get(\OCP\IConfig::class);
					$shieldUrl = (string) $config->getAppValue('souvera_mail', 'shield_url', '');
				} catch (\Throwable $e2) {
					$shieldUrl = '';
				}
			}
		}

		$webdavBase = \rtrim($sWebDAV, '/');
		$calDavUrl = $webdavBase . '/calendars/' . $sUID . '/';
		$cardDavUrl = $webdavBase . '/addressbooks/users/' . $sUID . '/';

		// Public FQDN — extracted from the Nextcloud WebDAV base URL
		// (which uses the same overwrite.cli.url / trusted_domain the
		// user reaches Nextcloud on). This is what external mail
		// clients MUST connect to. The engine domain-config JSON
		// contains an INTERNAL address (e.g. `10.20.0.129`) that only
		// works inside the cluster; showing that to a customer would
		// break every external Thunderbird / K-9 setup.
		//
		// Every mail-server host (IMAP / POP3 / SMTP / Sieve) is
		// overridden with this public hostname — Souvera clusters
		// front all four Stalwart ports through the same reverse
		// proxy the WebDAV URL points at.
		$publicHost = '';
		$parsedHost = \parse_url($sWebDAV, PHP_URL_HOST);
		if (\is_string($parsedHost) && $parsedHost !== '') {
			$publicHost = $parsedHost;
		}
		if ($publicHost !== '') {
			if ($imapHost !== '') { $imapHost = $publicHost; }
			if ($smtpHost !== '') { $smtpHost = $publicHost; }
			if ($sieveHost !== '') { $sieveHost = $publicHost; }
		}
		// Stalwart 0.16 ships POP3 on port 995 (implicit SSL) alongside
		// IMAP on 993. The host is the IMAP host — Stalwart's single
		// listener binds all four ports (POP3/IMAP/SMTP/Sieve). We
		// derive POP3 AFTER the public-host override so both surface
		// the public FQDN.
		$pop3Host = $imapHost;
		$pop3Port = $imapHost !== '' ? '995' : '';
		$pop3Ssl = $imapHost !== '' ? 'SSL' : '';

		// User's canonical mail address — best-effort. Fall back to the NC
		// profile email, then to the raw UID + domain, then to an empty
		// string (JS renders "—").
		$userEmail = '';
		try {
			$userEmail = (string) ($ocUser->getEMailAddress() ?? '');
		} catch (\Throwable $e) {
			// getEMailAddress may throw on some auth backends — swallow
		}
		if ($userEmail === '' && $domain !== '') {
			$userEmail = $sUID . '@' . $domain;
		}

		return [
			// `SmailHelpDomain` now surfaces the PUBLIC FQDN (was the
			// mail domain in earlier revisions). The Kalender-&-Kontakte
			// footer + iOS/Android walk-throughs need the actual host
			// the user reaches Nextcloud on, not the mail address suffix.
			'SmailHelpDomain' => $publicHost !== '' ? $publicHost : $domain,
			'SmailHelpEmail' => $userEmail,
			'SmailHelpImapHost' => $imapHost,
			'SmailHelpImapPort' => $imapPort,
			'SmailHelpImapSsl' => $imapSsl,
			'SmailHelpPop3Host' => $pop3Host,
			'SmailHelpPop3Port' => $pop3Port,
			'SmailHelpPop3Ssl' => $pop3Ssl,
			'SmailHelpSmtpHost' => $smtpHost,
			'SmailHelpSmtpPort' => $smtpPort,
			'SmailHelpSmtpSsl' => $smtpSsl,
			'SmailHelpSieveHost' => $sieveHost,
			'SmailHelpSievePort' => $sievePort,
			'SmailHelpSieveSsl' => $sieveSsl,
			'SmailHelpCalDavUrl' => $calDavUrl,
			'SmailHelpCardDavUrl' => $cardDavUrl,
			'SmailHelpShieldUrl' => $shieldUrl,
		];
	}

}
