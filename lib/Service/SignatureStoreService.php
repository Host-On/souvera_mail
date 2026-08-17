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
            try {
                $content = $file->getContent();
                return \is_string($content) ? $content : '';
            } catch (\Throwable $e) {
                // A broken file must never take down the whole settings
                // endpoint for this user.
                $this->logger->warning(
                    'Souvera Mail: signature file read failed for ' . $uid . ': ' . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
                return '';
            }
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

    /**
     * Per-identity signature file. Identity ids are strings like
     * "alias:foo@bar.com" — they are hashed into a safe file name.
     */
    public function readFor(string $uid, string $identityId): string
    {
        $file = $this->openIdentityFile($uid, $identityId, false);
        if ($file === null) {
            return '';
        }
        $content = $file->getContent();
        return \is_string($content) ? $content : '';
    }

    public function writeFor(string $uid, string $identityId, string $html): void
    {
        $file = $this->openIdentityFile($uid, $identityId, true);
        if ($file === null) {
            $this->logger->warning(
                'Souvera Mail: cannot open identity signature file for user ' . $uid,
                ['app' => 'souvera_mail']
            );
            throw new \RuntimeException('Cannot write identity signature file');
        }
        $file->putContent($html);
    }

    public function deleteFor(string $uid, string $identityId): void
    {
        try {
            $file = $this->openIdentityFile($uid, $identityId, false);
            if ($file !== null) {
                $file->delete();
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: identity signature delete failed for ' . $uid . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }
    }

    private function openIdentityFile(string $uid, string $identityId, bool $create): ?\OCP\Files\File
    {
        return $this->openFileNamed($uid, 'signature-' . \md5($identityId) . '.html', $create);
    }

    private function openFile(string $uid, bool $create): ?\OCP\Files\File
    {
        return $this->openFileNamed($uid, self::FILE, $create);
    }

    private function openFileNamed(string $uid, string $fileName, bool $create): ?\OCP\Files\File
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
            if (!$folder->nodeExists($fileName)) {
                if (!$create) {
                    return null;
                }
                $folder->newFile($fileName);
            }
            $node = $folder->get($fileName);
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
