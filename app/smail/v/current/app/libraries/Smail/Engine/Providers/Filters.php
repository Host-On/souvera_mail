<?php

namespace Smail\Engine\Providers;

class Filters extends \Smail\Engine\Providers\AbstractProvider
{
	/**
	 * @var \Smail\Engine\Providers\Filters\FiltersInterface
	 */
	private $oDriver;

	public function __construct(\Smail\Engine\Providers\Filters\FiltersInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private static function handleException(\Throwable $oException, int $defNotification) : void
	{
		if ($oException instanceof \Smail\Mail\Net\Exceptions\SocketCanNotConnectToHostException) {
			throw new \Smail\Engine\Exceptions\ClientException(\Smail\Engine\Notifications::ConnectionError->value, $oException);
		}

		if ($oException instanceof \Smail\Mail\Sieve\Exceptions\NegativeResponseException) {
			throw new \Smail\Engine\Exceptions\ClientException(
				\Smail\Engine\Notifications::ClientViewError->value, $oException, \implode("\r\n", $oException->GetResponses())
			);
		}

		throw new \Smail\Engine\Exceptions\ClientException($defNotification, $oException);
	}

	public function Load(\Smail\Engine\Model\Account $oAccount) : array
	{
		try
		{
			return $this->IsActive() ? $this->oDriver->Load($oAccount) : array();
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \Smail\Engine\Notifications::CantGetFilters->value);
		}
		return array();
	}

	public function Save(\Smail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Save($oAccount, $sScriptName, $sRaw)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \Smail\Engine\Notifications::CantSaveFilters->value);
		}
		return false;
	}

	public function ActivateScript(\Smail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Activate($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \Smail\Engine\Notifications::CantActivateFiltersScript->value);
		}
	}

	public function DeleteScript(\Smail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Delete($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \Smail\Engine\Notifications::CantDeleteFiltersScript->value);
		}
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof \Smail\Engine\Providers\Filters\FiltersInterface;
	}
}
