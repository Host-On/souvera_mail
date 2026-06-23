<?php

namespace Smail\Engine\Actions;

use Smail\Engine\Enumerations\Capa;

trait Filters
{
	private ?\Smail\Engine\Providers\Filters $oFiltersProvider = null;

	/**
	 * @throws \Smail\Mail\RuntimeException
	 */
	public function DoFilters() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->Load($oAccount));
	}

	/**
	 * @throws \Smail\Mail\RuntimeException
	 */
	public function DoFiltersScriptSave() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		$sName = $this->GetActionParam('name', '');

		if ($this->GetActionParam('active', false)) {
//			$this->FiltersProvider()->ActivateScript($oAccount, $sName);
		}

		return $this->DefaultResponse($this->FiltersProvider()->Save(
			$oAccount, $sName, $this->GetActionParam('body', '')
		));
	}

	/**
	 * @throws \Smail\Mail\RuntimeException
	 */
	public function DoFiltersScriptActivate() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->ActivateScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	/**
	 * @throws \Smail\Mail\RuntimeException
	 */
	public function DoFiltersScriptDelete() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->DeleteScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	protected function FiltersProvider() : \Smail\Engine\Providers\Filters
	{
		if (!$this->oFiltersProvider) {
			$this->oFiltersProvider = new \Smail\Engine\Providers\Filters($this->fabrica('filters'));
		}
		return $this->oFiltersProvider;
	}
}
