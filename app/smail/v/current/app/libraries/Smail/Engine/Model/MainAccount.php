<?php

namespace Smail\Engine\Model;

use Smail\Engine\Utils;
use Smail\Engine\Exceptions\ClientException;
use Smail\Engine\Notifications;
use Smail\Engine\Providers\Storage\Enumerations\StorageType;
use Smail\Engine\SensitiveString;

class MainAccount extends Account
{
	private ?SensitiveString $sCryptKey = null;

	public static function NewInstanceFromTokenArray(
		\Smail\Engine\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false
	) : ?MainAccount {
		$oAccount = parent::NewInstanceFromTokenArray($oActions, $aAccountHash, $bThrowExceptionOnFalse);

		return $oAccount instanceof MainAccount ? $oAccount : null;
	}

	public function resealCryptKey(SensitiveString $oOldPass) : bool
	{
		$oStorage = \Smail\Engine\Api::Actions()->StorageProvider();
		$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
		if ($sKey) {
			$sKey = \Smail\Engine\Crypt::DecryptFromJSON($sKey, $oOldPass);
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$sKey = \Smail\Engine\Crypt::EncryptToJSON($sKey, $this->ImapPass());
			if ($sKey) {
				$this->sCryptKey = null;
				if (\Smail\Engine\Api::Actions()->StorageProvider()->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey)) {
					return true;
				}
			}
		}
		return false;
	}

	public function CryptKey() : string
	{
		if (!$this->sCryptKey) {
			// Seal the cryptkey so that people who change their login password
			// can use the old password to re-seal the cryptkey
			$oStorage = \Smail\Engine\Api::Actions()->StorageProvider();
			$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
			if (!$sKey) {
				$sKey = \Smail\Engine\Crypt::EncryptToJSON(
					\sha1($this->ImapPass() . APP_SALT),
					$this->ImapPass()
				);
				$oStorage->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey);
			}
			$sKey = \Smail\Engine\Crypt::DecryptFromJSON($sKey, $this->ImapPass());
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$this->sCryptKey = new SensitiveString(\hex2bin($sKey));
		}
		return $this->sCryptKey;
	}

/*
	// Stores settings in MainAccount
	public function settings() : \Smail\Engine\Settings
	{
		return \Smail\Engine\Api::Actions()->SettingsProvider()->Load($this);
	}
*/
}
