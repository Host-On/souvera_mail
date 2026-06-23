<?php

namespace Smail\Engine\Providers\Filters;

interface FiltersInterface
{
	public function Load(\Smail\Engine\Model\Account $oAccount) : array;

	public function Save(\Smail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool;

	public function Activate(\Smail\Engine\Model\Account $oAccount, string $sScriptName) : bool;

	public function Delete(\Smail\Engine\Model\Account $oAccount, string $sScriptName) : bool;
}
