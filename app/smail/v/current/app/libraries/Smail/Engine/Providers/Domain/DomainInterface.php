<?php

namespace Smail\Engine\Providers\Domain;

interface DomainInterface
{
	public function Disable(string $sName, bool $bDisable) : bool;

	public function Load(string $sName, bool $bFindWithWildCard = false, bool $bCheckDisabled = true, bool $bCheckAliases = true) : ?\Smail\Engine\Model\Domain;

	public function Save(\Smail\Engine\Model\Domain $oDomain) : bool;

	public function SaveAlias(string $sName, string $sAlias) : bool;

	public function Delete(string $sName) : bool;

	public function GetList(bool $bIncludeAliases = true) : array;
}
