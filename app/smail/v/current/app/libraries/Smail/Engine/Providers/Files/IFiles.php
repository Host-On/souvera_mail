<?php

namespace Smail\Engine\Providers\Files;

interface IFiles
{
	public function GenerateLocalFullFileName(\Smail\Engine\Model\Account $oAccount, string $sKey) : string;

	public function PutFile(\Smail\Engine\Model\Account $oAccount, string $sKey, /*resource*/ $rSource) : bool;

	public function MoveUploadedFile(\Smail\Engine\Model\Account $oAccount, string $sKey, string $sSource) : bool;

	/**
	 * @return resource|bool
	 */
	public function GetFile(\Smail\Engine\Model\Account $oAccount, string $sKey, string $sOpenMode = 'rb');

	/**
	 * @return string|bool
	 */
	public function GetFileName(\Smail\Engine\Model\Account $oAccount, string $sKey);

	public function Clear(\Smail\Engine\Model\Account $oAccount, string $sKey) : bool;

	/**
	 * @return int | bool
	 */
	public function FileSize(\Smail\Engine\Model\Account $oAccount, string $sKey);

	public function FileExists(\Smail\Engine\Model\Account $oAccount, string $sKey) : bool;

	public function GC(int $iTimeToClearInHours = 24) : bool;
}
