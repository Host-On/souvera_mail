<?php

namespace Smail\Engine\Providers\Settings;

interface ISettings
{
	public function Load(\Smail\Engine\Model\Account $oAccount) : array;

	public function Save(\Smail\Engine\Model\Account $oAccount, \Smail\Engine\Settings $oSettings) : bool;

	public function Delete(\Smail\Engine\Model\Account $oAccount) : bool;
}
