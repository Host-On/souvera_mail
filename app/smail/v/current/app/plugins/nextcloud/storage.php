<?php

use Smail\Engine\Providers\Storage\Enumerations\StorageType;

class NextcloudStorage extends \Smail\Engine\Providers\Storage\FileStorage
{
	/**
	 * @param \Smail\Engine\Model\Account|string|null $mAccount
	 */
	public function GenerateFilePath($mAccount, int $iStorageType, bool $bMkDir = false) : string
	{
		$sDataPath = parent::GenerateFilePath($mAccount, $iStorageType, $bMkDir);
		if (StorageType::CONFIG === $iStorageType) {
			// Per-user subfolder. Guard against session-less contexts
			// (cron, dashboard widgets, push, search provider): without
			// a logged-in user the previous code threw a fatal on
			// `->getUser()->getUID()` and silently dropped the write —
			// the UI showed "saved" but nothing was persisted.
			$sUID = 'system';
			try {
				$oUser = \OC::$server->getUserSession()->getUser();
				if (null !== $oUser) {
					$sUID = $oUser->getUID();
				}
			} catch (\Throwable) {
			}
			$sDataPath .= ".config/{$sUID}/";
			if ($bMkDir && !\is_dir($sDataPath) && !\mkdir($sDataPath, 0700, true)) {
				throw new \Smail\Engine\Exceptions\Exception('Can\'t make storage directory "'.$sDataPath.'"');
			}
		}
		return $sDataPath;
	}
}
