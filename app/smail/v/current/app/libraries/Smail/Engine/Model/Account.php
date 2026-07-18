<?php

namespace Smail\Engine\Model;

use Smail\Engine\Utils;
use Smail\Engine\Notifications;
use Smail\Engine\Exceptions\ClientException;
use Smail\Engine\SensitiveString;

abstract class Account implements \JsonSerializable
{
	private string $sName = '';

	private string $sEmail = '';

	private string $sImapUser = '';

	private ?SensitiveString $oImapPass = null;

	private string $sSmtpUser = '';

	private ?SensitiveString $oSmtpPass = null;

	private ?Domain $oDomain = null;

	/**
	 * v0.16.0 — Manual-server flag for external accounts (Issue #1).
	 *
	 * When an AdditionalAccount is created via the popup's "Manual server
	 * configuration" toggle (custom IMAP/SMTP host + port + security), we
	 * MUST persist the Domain config inside the per-user token array —
	 * otherwise NewInstanceFromTokenArray() on every subsequent request
	 * would fall back to DomainProvider::getByEmailAddress(), which
	 * throws for custom domains not registered in Snappymail's global
	 * domain store (nor Mozilla ISPDB).
	 *
	 * Setting this to true instructs jsonSerialize() to embed the domain
	 * config into the token; NewInstanceFromTokenArray() then rebuilds
	 * the Domain via Domain::fromArray() instead of the provider lookup.
	 */
	private bool $bManualDomain = false;

	public function Email() : string
	{
		return $this->sEmail;
	}

	public function Name() : string
	{
		return $this->sName;
	}

	public function ImapUser() : string
	{
		return $this->sImapUser;
	}

	public function ImapPass() : string
	{
		return $this->oImapPass ? $this->oImapPass->getValue() : '';
	}

	public function SmtpUser() : string
	{
		return $this->sSmtpUser ?: ($this->oDomain ? $this->oDomain->SmtpSettings()->fixUsername($this->sEmail) : '');
//		return $this->sSmtpUser ?: $this->sEmail ?: $this->sImapUser;
	}

	public function Domain() : ?Domain
	{
		return $this->oDomain;
	}

	/**
	 * v0.16.0 — Marks this account as using a manually-configured Domain
	 * (custom IMAP/SMTP host+port, not looked up via DomainProvider).
	 * Called from LoginProcess() right after setCredentials() when the
	 * user supplied manual server details in the "Add account" popup.
	 */
	public function setManualDomain(bool $b) : void
	{
		$this->bManualDomain = $b;
	}

	/**
	 * v0.16.0 — Returns true if this account's Domain was manually
	 * configured (i.e. must be persisted in the token, not resolved via
	 * DomainProvider on load).
	 */
	public function isManualDomain() : bool
	{
		return $this->bManualDomain;
	}

	public function Hash() : string
	{
		return \sha1(\implode(APP_SALT, [
			$this->sEmail,
			$this->sImapUser,
//			\json_encode($this->Domain()),
//			$this->oImapPass
		]));
	}

	public function setImapUser(string $sImapUser) : void
	{
		$this->sImapUser = $sImapUser;
	}

	public function setImapPass(SensitiveString $oPassword) : void
	{
		$this->oImapPass = $oPassword;
	}

	public function setSmtpUser(string $sSmtpUser) : void
	{
		$this->sSmtpUser = $sSmtpUser;
	}

	public function setSmtpPass(SensitiveString $oPassword) : void
	{
		$this->oSmtpPass = $oPassword;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		$result = [
			'email' => $this->sEmail,
			'login' => $this->sImapUser,
			'pass'  => $this->ImapPass(),
			'name' => $this->sName,
			'smtp' => []
		];
		if ($this->sSmtpUser) {
			$result['smtp']['user'] = $this->sSmtpUser;
		}
		if ($this->oSmtpPass) {
			$result['smtp']['pass'] = $this->oSmtpPass->getValue();
		}
		// v0.16.0 — Persist the manually-configured Domain into the
		// per-user token array so NewInstanceFromTokenArray() can rebuild
		// it via Domain::fromArray() on every request (bypassing the
		// DomainProvider lookup that would otherwise throw "has no
		// domain configuration" for custom domains).
		if ($this->bManualDomain && $this->oDomain) {
			$oImap = $this->oDomain->ImapSettings();
			$oSmtp = $this->oDomain->SmtpSettings();
			$oSieve = $this->oDomain->SieveSettings();
			$result['domain'] = [
				'IMAP' => [
					'host' => $oImap->host,
					'port' => $oImap->port,
					'type' => $oImap->type,
					'sasl' => $oImap->SASLMechanisms,
				],
				'SMTP' => [
					'host' => $oSmtp->host,
					'port' => $oSmtp->port,
					'type' => $oSmtp->type,
					'useAuth' => $oSmtp->useAuth,
					'sasl' => $oSmtp->SASLMechanisms,
				],
				'Sieve' => [
					'enabled' => $oSieve->enabled,
					'host' => $oSieve->host,
					'port' => $oSieve->port,
					'type' => $oSieve->type,
				],
				'whiteList' => '',
			];
		}
		return $result;
	}

	public function setCredentials(
		Domain $oDomain,
		string $sEmail,
		string $sImapUser,
		SensitiveString $oImapPass,
		string $sSmtpUser = '',
		?SensitiveString $oSmtpPass = null
	) {
		$this->sEmail = $sEmail;
		$this->oDomain = $oDomain;
		$this->sImapUser = $sImapUser;
		$this->oImapPass = $oImapPass;
		$this->sSmtpUser = $sSmtpUser;
		$this->oSmtpPass = $oSmtpPass;
	}

	/**
	 * Converts old numeric array to new associative array
	 */
	public static function convertArray(array $aAccount) : array
	{
		if (isset($aAccount['email'])) {
			return $aAccount;
		}
		if (empty($aAccount[0]) || 'account' != $aAccount[0] || 7 > \count($aAccount)) {
			return [];
		}
		return [
			'email' => $aAccount[1] ?: '',
			'login' => $aAccount[2] ?: '',
			'pass'  => $aAccount[3] ?: ''
		];
	}

	public static function NewInstanceFromTokenArray(
		\Smail\Engine\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false): ?self
	{
		$oAccount = null;
		$aAccountHash = static::convertArray($aAccountHash);
		try {
/*
			if (empty($aAccountHash['email'])) {
				throw new ClientException(Notifications::InvalidToken->value, null, 'TokenArray missing email');
			}
			if (empty($aAccountHash['login'])) {
				throw new ClientException(Notifications::InvalidToken->value, null, 'TokenArray missing login');
			}
			if (empty($aAccountHash['pass'])) {
				throw new ClientException(Notifications::InvalidToken->value, null, 'TokenArray missing pass');
			}
*/
			if (empty($aAccountHash['email']) || empty($aAccountHash['login']) || empty($aAccountHash['pass'])) {
				throw new \RuntimeException("Invalid TokenArray");
			}
			// v0.16.0 — If the token carries a persisted manual Domain
			// configuration (external account added via the popup's
			// "Manual server configuration" toggle), rebuild the Domain
			// from that config instead of the global DomainProvider —
			// which would otherwise throw "has no domain configuration"
			// for custom domains like `philip@uelzen.email` that never
			// existed in the ISPDB or the Snappymail domain registry.
			$bManualDomain = !empty($aAccountHash['domain']) && \is_array($aAccountHash['domain']);
			if ($bManualDomain) {
				$sDomainName = \Smail\Mail\Base\Utils::getEmailAddressDomain($aAccountHash['email']) ?: 'external';
				$oDomain = Domain::fromArray($sDomainName, $aAccountHash['domain']);
			} else {
				$oDomain = $oActions->DomainProvider()->getByEmailAddress($aAccountHash['email']);
			}
			if ($oDomain) {
//				$aAccountHash['email'] = $oDomain->ImapSettings()->fixUsername($aAccountHash['email'], false);
//				$aAccountHash['login'] = $oDomain->ImapSettings()->fixUsername($aAccountHash['login']);
				/** @phpstan-ignore new.static */
				$oAccount = new static;
				$oAccount->sEmail = \Smail\Engine\IDN::emailToAscii($aAccountHash['email']);
//				$oAccount->sImapUser = \Smail\Engine\IDN::emailToAscii($aAccountHash['login']);
				$oAccount->sImapUser = $aAccountHash['login'];
				$oAccount->setImapPass(new SensitiveString($aAccountHash['pass']));
				$oAccount->oDomain = $oDomain;
				$oAccount->bManualDomain = $bManualDomain;
				$oActions->Plugins()->RunHook('filter.account', array($oAccount));
				if (!$oAccount) {
					throw new ClientException(Notifications::AccountFilterError->value);
				}
				if (isset($aAccountHash['name'])) {
					$oAccount->sName = $aAccountHash['name'];
				}
				// init smtp user/password
				if (isset($aAccountHash['smtp']['user'])) {
					$oAccount->sSmtpUser = $aAccountHash['smtp']['user'];
				}
				if (isset($aAccountHash['smtp']['pass'])) {
					$oAccount->setSmtpPass(new SensitiveString($aAccountHash['smtp']['pass']));
				}
			}
		} catch (\Throwable $e) {
			\Smail\Engine\Log::debug('ACCOUNT', $e->getMessage());
			if ($bThrowExceptionOnFalse) {
				throw $e;
			}
		}
		return $oAccount;
	}

	public function ImapConnectAndLogin(\Smail\Engine\Plugins\Manager $oPlugins, \Smail\Mail\Imap\ImapClient $oImapClient, \Smail\Engine\Config\Application $oConfig) : bool
	{
		$oSettings = $this->Domain()->ImapSettings();
		$oSettings->timeout = \max($oSettings->timeout, (int) $oConfig->Get('imap', 'timeout', $oSettings->timeout));
		$oSettings->username = $this->ImapUser();

		$oSettings->expunge_all_on_delete = $oSettings->expunge_all_on_delete || !!$oConfig->Get('imap', 'use_expunge_all_on_delete', false);
		$oSettings->fast_simple_search = !(!$oSettings->fast_simple_search || !$oConfig->Get('imap', 'message_list_fast_simple_search', true));
		$oSettings->fetch_new_messages = !(!$oSettings->fetch_new_messages || !$oConfig->Get('imap', 'fetch_new_messages', true));
		$oSettings->force_select = $oSettings->force_select || !!$oConfig->Get('imap', 'use_force_selection', false);
		$oSettings->message_all_headers = $oSettings->message_all_headers || !!$oConfig->Get('imap', 'message_all_headers', false);
		$oSettings->search_filter = $oSettings->search_filter ?: \trim($oConfig->Get('imap', 'message_list_permanent_filter', ''));
//		$oSettings->body_text_limit = \min($oSettings->body_text_limit, (int) $oConfig->Get('imap', 'body_text_limit', 50));
//		$oSettings->thread_limit = \min($oSettings->thread_limit, (int) $oConfig->Get('imap', 'large_thread_limit', 50));

		$oImapClient->Settings = $oSettings;

		$oPlugins->RunHook('imap.before-connect', array($this, $oImapClient, $oSettings));
		$oImapClient->Connect($oSettings);
		$oPlugins->RunHook('imap.after-connect', array($this, $oImapClient, $oSettings));

		$oSettings->passphrase = $this->oImapPass;
		return $this->netClientLogin($oImapClient, $oPlugins);
	}

	public function SmtpConnectAndLogin(\Smail\Engine\Plugins\Manager $oPlugins, \Smail\Mail\Smtp\SmtpClient $oSmtpClient) : bool
	{
		$oSettings = $this->Domain()->SmtpSettings();
		$oSettings->username = $this->SmtpUser();
		$oSettings->Ehlo = \Smail\Mail\Smtp\SmtpClient::EhloHelper();

		$oSmtpClient->Settings = $oSettings;

		$oPlugins->RunHook('smtp.before-connect', array($this, $oSmtpClient, $oSettings));
		$oSmtpClient->Connect($oSettings);
		$oPlugins->RunHook('smtp.after-connect', array($this, $oSmtpClient, $oSettings));
/*
		if ($this->oDomain->OutAskCredentials() && !($this->oSmtpPass && $this->sSmtpUser)) {
			throw new RequireCredentialsException
		}
*/
		$oSettings->passphrase = $this->oSmtpPass ?: $this->oImapPass;
		return $this->netClientLogin($oSmtpClient, $oPlugins);
	}

	public function SieveConnectAndLogin(\Smail\Engine\Plugins\Manager $oPlugins, \Smail\Mail\Sieve\SieveClient $oSieveClient, \Smail\Engine\Config\Application $oConfig)
	{
		$oSettings = $this->Domain()->SieveSettings();
		$oSettings->username = $this->ImapUser();

		$oSieveClient->Settings = $oSettings;

		$oPlugins->RunHook('sieve.before-connect', array($this, $oSieveClient, $oSettings));
		$oSieveClient->Connect($oSettings);
		$oPlugins->RunHook('sieve.after-connect', array($this, $oSieveClient, $oSettings));

		$oSettings->passphrase = $this->oImapPass;
		return $this->netClientLogin($oSieveClient, $oPlugins);
	}

	private function netClientLogin(\Smail\Mail\Net\NetClient $oClient, \Smail\Engine\Plugins\Manager $oPlugins) : bool
	{
/*
		$encrypted = !empty(\stream_get_meta_data($oClient->ConnectionResource())['crypto']);
		[crypto] => Array(
			[protocol] => TLSv1.3
			[cipher_name] => TLS_AES_256_GCM_SHA384
			[cipher_bits] => 256
			[cipher_version] => TLSv1.3
		)
*/
		$oSettings = $oClient->Settings;

		$client_name = \strtolower($oClient->getLogName());

		$oPlugins->RunHook("{$client_name}.before-login", array($this, $oClient, $oSettings));
		$bResult = !$oSettings->useAuth || $oClient->Login($oSettings);
		$oPlugins->RunHook("{$client_name}.after-login", array($this, $oClient, $bResult, $oSettings));
		return $bResult;
	}

/*
	// Stores settings in AdditionalAccount else MainAccount
	public function settingsLocal() : \Smail\Engine\Settings
	{
		return \Smail\Engine\Api::Actions()->SettingsProvider(true)->Load($this);
	}
*/

	/**
	 * @deprecated since v2.36.1
	 */
	public function IncLogin() : string
	{
		return $this->ImapUser();
	}
	public function IncPassword() : string
	{
		return $this->ImapPass();
	}
	public function OutLogin() : string
	{
		return $this->SmtpUser();
	}
	public function SetPassword(SensitiveString $oPassword) : void
	{
		$this->oImapPass = $oPassword;
	}
}
