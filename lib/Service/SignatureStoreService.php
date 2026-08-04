<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stores the user's HTML signature as a FILE in the user's data directory
 * instead of a Nextcloud user-preference value.
 *
 * Why: `user_preferences.configvalue` is a MySQL TEXT column (64 KB limit).
 * HTML signatures with embedded base64 images easily exceed that, which
 * made saving fail silently. Files have no practical size limit here.
 *
 * Path: <datadir>/<uid>/souvera_mail/signature.html
 *
 * Legacy values previously stored in the `pref_signature_html` user-preference
 * are read as a fallback and migrated to the file on the next write.
 */
class SignatureStoreService
{
    private const FOLDER = 'souvera_mail';
    private const FILE = 'signature.html';
    private const LEGACY_PREF = 'pref_signature_html';

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function read(string $uid): string
    {
        $file = $this->openFile($uid, false);
        if ($file !== null) {
            $content = $file->getContent();
            return \is_string($content) ? $content : '';
        }
        // Migration fallback: legacy user-preference value.
        return (string) $this->config->getUserValue($uid, 'souvera_mail', self::LEGACY_PREF, '');
    }

    public function write(string $uid, string $html): void
    {
        $file = $this->openFile($uid, true);
        if ($file === null) {
            $this->logger->warning(
                'Souvera Mail: cannot open signature file for user ' . $uid,
                ['app' => 'souvera_mail']
            );
            throw new \RuntimeException('Cannot write signature file');
        }
        $file->putContent($html);
        // The file is now the source of truth — drop the legacy preference.
        $this->config->deleteUserValue($uid, 'souvera_mail', self::LEGACY_PREF);
    }

    private function openFile(string $uid, bool $create): ?\OCP\Files\File
    {
        try {
            $home = $this->rootFolder->get($uid);
            if (!$home instanceof \OCP\Files\Folder) {
                return null;
            }
            if (!$home->nodeExists(self::FOLDER)) {
                if (!$create) {
                    return null;
                }
                $home->newFolder(self::FOLDER);
            }
            $folder = $home->get(self::FOLDER);
            if (!$folder instanceof \OCP\Files\Folder) {
                return null;
            }
            if (!$folder->nodeExists(self::FILE)) {
                if (!$create) {
                    return null;
                }
                $folder->newFile(self::FILE);
            }
            $node = $folder->get(self::FILE);
            return $node instanceof \OCP\Files\File ? $node : null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: signature file access failed for ' . $uid . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return null;
        }
    }
}
