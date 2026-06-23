<?php

namespace Smail\Engine\Providers\Suggestions;

interface ISuggestions
{
//	use \Smail\Mail\Log\Inherit;
	public function Process(\Smail\Engine\Model\Account $oAccount, string $sQuery, int $iLimit = 20) : array;
//	public function SetLogger(\Smail\Mail\Log\Logger $oLogger) : void
}
