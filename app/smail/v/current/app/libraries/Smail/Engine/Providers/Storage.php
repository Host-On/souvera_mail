<?php

namespace Smail\Engine\Providers;

class Storage extends \Smail\Engine\Providers\AbstractProvider
{
	/**
	 * @var \Smail\Engine\Providers\Storage\IStorage
	 */
	private $oDriver;

	public function __construct(\Smail\Engine\Providers\Storage\IStorage $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	private function verifyAccount($mAccount, int $iStorageType) : bool
	{
		return \Smail\Engine\Providers\Storage\Enumerations\StorageType::NOBODY->value === $iStorageType
			|| $mAccount instanceof \Smail\Engine\Model\Account
			|| \is_string($mAccount);
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function Put($mAccount, int $iStorageType, string $sKey, string $sValue) : bool
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Put($mAccount, $iStorageType, $sKey, $sValue)
			: false;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 * @param mixed $mDefault = false
	 *
	 * @return mixed
	 */
	public function Get($mAccount, int $iStorageType, string $sKey, $mDefault = false)
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Get($mAccount, $iStorageType, $sKey, $mDefault)
			: $mDefault;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function Clear($mAccount, int $iStorageType, string $sKey) : bool
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Clear($mAccount, $iStorageType, $sKey)
			: false;
	}

	/**
	 * @param \Smail\Engine\Model\Account|string $mAccount
	 */
	public function DeleteStorage($mAccount) : bool
	{
		return $this->oDriver->DeleteStorage($mAccount);
	}

	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		return $this->oDriver->GenerateFilePath($mAccount, $iStorageType, $bMkDir);
	}

	public function IsActive() : bool
	{
		return true;
	}

	public function IsLocal() : bool
	{
		return $this->oDriver->IsLocal();
	}

	public function GC() : void
	{
		$this->oDriver->GC();
	}
}
