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
			// Per-user subfolder. Session-less contexts (cron, background
			// jobs) have no logged-in user — resolve the uid from the
			// account when possible so background processes read/write the
			// SAME settings the UI uses (never a shared "system" folder).
			$sUID = 'system';
			try {
				$oUser = \OC::$server->getUserSession()->getUser();
				if (null !== $oUser) {
					$sUID = $oUser->getUID();
				} else {
					$sCandidate = ($mAccount instanceof \Smail\Engine\Model\Account) ? $mAccount->ImapUser() : (string)$mAccount;
					if ('' !== $sCandidate) {
						$oUserManager = \OC::$server->getUserManager();
						if ($oUserManager->userExists($sCandidate)) {
							$sUID = $sCandidate;
						} else {
							$aParts = \explode('@', $sCandidate);
							if (1 < \count($aParts) && $oUserManager->userExists($aParts[0])) {
								$sUID = $aParts[0];
							} else {
								$aUsers = $oUserManager->getByEmail($sCandidate);
								if ([] !== $aUsers) {
									$sUID = \array_key_first($aUsers);
								}
							}
						}
					}
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
