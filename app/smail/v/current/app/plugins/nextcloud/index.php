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
			$this->addJs('js/folder-names.js');

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
//				'WebDAV_files' => $sWebDAV . '/files/' . $sUID
			];
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

}
