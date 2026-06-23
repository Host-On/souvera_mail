<?php

namespace Smail\Engine\Providers;

use Smail\Engine\Model\Account;
use Smail\Engine\Providers\Settings\ISettings;

class Settings extends \Smail\Engine\Providers\AbstractProvider
{
	private ISettings $oDriver;

	public function __construct(ISettings $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function Load(Account $oAccount) : \Smail\Engine\Settings
	{
		return new \Smail\Engine\Settings($this, $oAccount, $this->oDriver->Load($oAccount));
	}

	public function Save(Account $oAccount, \Smail\Engine\Settings $oSettings) : bool
	{
		return $this->oDriver->Save($oAccount, $oSettings);
	}

	public function IsActive() : bool
	{
		return true;
	}
}
