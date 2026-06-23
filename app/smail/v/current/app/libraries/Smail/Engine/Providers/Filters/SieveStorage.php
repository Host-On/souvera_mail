<?php

namespace Smail\Engine\Providers\Filters;

class SieveStorage implements FiltersInterface
{
	use \Smail\Mail\Log\Inherit;

	const SIEVE_FILE_NAME = 'smail.user';

	/**
	 * @var \Smail\Engine\Plugins\Manager
	 */
	private $oPlugins;

	/**
	 * @var \Smail\Engine\Config\Application
	 */
	private $oConfig;

	public function __construct($oPlugins, $oConfig)
	{
		$this->oPlugins = $oPlugins;
		$this->oConfig = $oConfig;
	}

	protected function getConnection(\Smail\Engine\Model\Account $oAccount) : ?\Smail\Mail\Sieve\SieveClient
	{
		$oSieveClient = new \Smail\Mail\Sieve\SieveClient();
		$oSieveClient->SetLogger($this->oLogger);
		return $oAccount->SieveConnectAndLogin($this->oPlugins, $oSieveClient, $this->oConfig)
			 ? $oSieveClient
			 : null;
	}

	public function Load(\Smail\Engine\Model\Account $oAccount) : array
	{
		$aModules = array();
		$aScripts = array();

		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$aModules = $oSieveClient->Modules();
			\sort($aModules);

			$aList = $oSieveClient->ListScripts();

			foreach ($aList as $name => $active) {
				$aScripts[$name] = array(
					'@Object' => 'Object/SieveScript',
					'name' => $name,
					'active' => $active,
					'body' => $oSieveClient->GetScript($name)
				);
			}

			$oSieveClient->Disconnect();

			if (!isset($aList[self::SIEVE_FILE_NAME])) {
				$aScripts[self::SIEVE_FILE_NAME] = array(
					'@Object' => 'Object/SieveScript',
					'name' => self::SIEVE_FILE_NAME,
					'active' => false,
					'body' => ''
				);
			}
		}

		\ksort($aScripts);

		return array(
			'Capa' => $aModules,
			'Scripts' => $aScripts
		);
	}

	public function Save(\Smail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->PutScript($sScriptName, $sRaw);
			return true;
		}
		return false;
	}

	/**
	 * If $sScriptName is the empty string (i.e., ""), then any active script is disabled.
	 */
	public function Activate(\Smail\Engine\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->SetActiveScript(\trim($sScriptName));
			return true;
		}
		return false;
	}
/*
	public function Check(\Smail\Engine\Model\Account $oAccount, string $sScript) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->CheckScript($sScript);
			return true;
		}
		return false;
	}
*/
	public function Delete(\Smail\Engine\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			if (isset($oSieveClient->ListScripts()[$sScriptName])) {
				$oSieveClient->DeleteScript(\trim($sScriptName));
			}
			return true;
		}
		return false;
	}
}
