<?php

namespace Smail\Engine\Providers;

abstract class AbstractProvider
{
	use \Smail\Mail\Log\Inherit;

	abstract public function IsActive() : bool;
}
