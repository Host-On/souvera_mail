<?php

namespace Smail\Engine\Providers\Storage;

use Smail\Engine\Providers\Storage\Enumerations\StorageType;

class FileStorage implements \Smail\Engine\Providers\Storage\IStorage
{
	use \Smail\Mail\Log\Inherit;

	protected string $sDataPath;

	private bool $bLocal;

	public function __construct(string $sStoragePath, bool $bLocal = false)
	{
		$this->sDataPath = \rtrim(\trim($sStoragePath), '\\/');
		$this->bLocal = $bLocal;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool
	{
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey, true);
		try {
			// Souvera Mail patch (v0.22.7): an empty filename used to be
			// treated as success (`$sFileName && saveFile(...)` + return
			// true) — settings silently vanished after a reload. Log and
			// report the failure instead.
			if (!$sFileName) {
				\Smail\Engine\Log::warning('FileStorage', 'Put() got an empty filename for key "' . $sKey . '" — not persisted');
				return false;
			}
			\Smail\Engine\Utils::saveFile($sFileName, $sValue);
			return true;
		} catch (\Throwable $e) {
			\Smail\Engine\Log::warning('FileStorage', $e->getMessage());
		}
		return false;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 * @param mixed $mDefault = false
	 *
	 * @return mixed
	 */
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false)
	{
		$mValue = false;
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey);
		if ($sFileName && \file_exists($sFileName)) {
			$mValue = \file_get_contents($sFileName);
			// Update mtime to prevent garbage collection
			if (StorageType::SESSION->value === $iStorageType) {
				\touch($sFileName);
			}
		}
		return false === $mValue ? $mDefault : $mValue;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool
	{
		$sFileName = $this->generateFileName($mAccount, $iStorageType, $sKey);
		return $sFileName && \file_exists($sFileName) && \unlink($sFileName);
	}

	/**
	 * @param \Smail\Engine\Model\Account|string $mAccount
	 */
	public function DeleteStorage($mAccount) : bool
	{
		$sPath = $this->generateFileName($mAccount, StorageType::CONFIG->value, '');
		if ($sPath && \is_dir($sPath)) {
			\Smail\Mail\Base\Utils::RecRmDir($sPath);
		}
		return true;
	}

	public function IsLocal() : bool
	{
		return $this->bLocal;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		$sEmail = $sSubFolder = $sFilePath = '';
		if (null === $mAccount || StorageType::NOBODY->value === $iStorageType) {
			$sFilePath = $this->sDataPath.'/__nobody__/';
		} else {
			if ($mAccount instanceof \Smail\Engine\Model\MainAccount) {
				$sEmail = $mAccount->Email();
			} else if ($mAccount instanceof \Smail\Engine\Model\AdditionalAccount) {
				$sEmail = $mAccount->ParentEmail();
				if ($this->bLocal) {
					$sSubFolder = $mAccount->Email();
				}
			} else if (\is_string($mAccount)) {
				$sEmail = $mAccount;
			}

			if ($sEmail) {
				// these are never local
				if (StorageType::SIGN_ME->value === $iStorageType) {
					$sSubFolder = '.sign_me';
				} else if (StorageType::SESSION->value === $iStorageType) {
					$sSubFolder = '.sessions';
				} else if (StorageType::PGP->value === $iStorageType) {
					$sSubFolder = '.pgp';
				} else if (StorageType::ROOT->value === $iStorageType) {
					$sSubFolder = '';
				}
			}

			switch ($iStorageType)
			{
				case StorageType::CONFIG->value:
				case StorageType::SIGN_ME->value:
				case StorageType::SESSION->value:
				case StorageType::PGP->value:
				case StorageType::ROOT->value:
					if (empty($sEmail)) {
						return '';
					}
					if (\is_dir("{$this->sDataPath}/cfg")) {
						\Smail\Engine\Upgrade::FileStorage($this->sDataPath);
					}
					$aEmail = \explode('@', $sEmail ?: 'nobody@unknown.tld');
					$sDomain = \trim(1 < \count($aEmail) ? \array_pop($aEmail) : '');
					$sFilePath = $this->sDataPath
						.'/'.\Smail\Mail\Base\Utils::SecureFileName($sDomain ?: 'unknown.tld')
						.'/'.\Smail\Mail\Base\Utils::SecureFileName(\implode('@', $aEmail) ?: '.unknown')
						.'/'.($sSubFolder ? \Smail\Mail\Base\Utils::SecureFileName($sSubFolder).'/' : '');
					break;
				default:
					throw new \Exception("Invalid storage type {$iStorageType}");
			}
		}

		$bMkDir && $sFilePath && \Smail\Mail\Base\Utils::mkdir($sFilePath);

		return $sFilePath;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	protected function generateFileName($mAccount, int $iStorageType, string $sKey, bool $bMkDir = false) : string
	{
		$sFilePath = $this->GenerateFilePath($mAccount, $iStorageType, $bMkDir);
		if ($sFilePath) {
			if (StorageType::NOBODY->value === $iStorageType) {
				$sFilePath .= \sha1($sKey ?: \time());
			} else {
				$sFilePath .= ($sKey ? \Smail\Mail\Base\Utils::SecureFileName($sKey) : '');
			}
		}
		return $sFilePath;
	}

	public function GC() : void
	{
		\clearstatcache();
		foreach (\glob("{$this->sDataPath}/*", GLOB_ONLYDIR) as $sDomain) {
			foreach (\glob("{$sDomain}/*", GLOB_ONLYDIR) as $sLocal) {
				\Smail\Mail\Base\Utils::RecTimeDirRemove("{$sLocal}/.sign_me", 3600 * 24 * 30); // 30 days
				\Smail\Mail\Base\Utils::RecTimeDirRemove("{$sLocal}/.sessions", 3600 * 3); // 3 hours
			}
		}
	}
}
