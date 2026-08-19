<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Manages external IMAP/SMTP account credentials for the v2 frontend.
 *
 * Credentials are stored as JSON arrays in app-config, encrypted with
 * Nextcloud's ICrypto. Each user can have multiple accounts (default cap: 3).
 *
 * Storage key: `ext_account.<uid>.<hash>` → encrypted JSON
 * JSON shape: {email, imap_host, imap_port, imap_ssl, smtp_host, smtp_port,
 *              smtp_ssl, username, password, created_at, provider}
 */
class ExternalAccountService
{
    private const KEY_PREFIX = 'ext_account.';

    public function __construct(
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private ExternalAccountsConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List all external accounts for a user.
     *
     * @return array<int,array>
     */
    public function listForUser(string $uid): array
    {
        $prefix = self::KEY_PREFIX . $uid . '.';
        $keys = $this->appConfig->getKeys('souvera_mail');
        $accounts = [];

        foreach ($keys as $key) {
            if (!\str_starts_with($key, $prefix)) continue;
            try {
                $encrypted = $this->appConfig->getValueString('souvera_mail', $key, '');
                if ($encrypted === '') continue;
                $decrypted = $this->crypto->decrypt($encrypted);
                $data = \json_decode($decrypted, true);
                if (!\is_array($data)) continue;
                $data['id'] = \substr($key, \strlen($prefix));
                unset($data['password']); // never expose plaintext
                $accounts[] = $data;
            } catch (\Throwable $e) {
                $this->logger->warning('ExternalAccountService: failed to decrypt ' . $key, ['exception' => $e]);
            }
        }

        return $accounts;
    }

    /**
     * Add a new external account.
     *
     * @return array The created account (without password).
     * @throws \RuntimeException on cap exceeded, duplicate, or invalid params.
     */
    public function add(string $uid, array $data): array
    {
        $email = \strtolower(\trim((string) ($data['email'] ?? '')));
        if ($email === '' || !\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email address');
        }

        // Cap check
        $existing = $this->listForUser($uid);
        $max = $this->config->getMaxAccountsPerUser();
        if (\count($existing) >= $max) {
            throw new \RuntimeException("Maximum of {$max} external accounts reached");
        }

        $hash = \substr(\sha1($email), 0, 12);
        $key = self::KEY_PREFIX . $uid . '.' . $hash;

        // Check for duplicates
        if ($this->appConfig->hasKey('souvera_mail', $key)) {
            throw new \RuntimeException('Account already exists for ' . $email);
        }

        $entry = [
            'email' => $email,
            'imap_host' => \trim((string) ($data['imap_host'] ?? '')),
            'imap_port' => (int) ($data['imap_port'] ?? 993),
            'imap_ssl'  => (string) ($data['imap_ssl'] ?? 'ssl'),
            'smtp_host' => \trim((string) ($data['smtp_host'] ?? '')),
            'smtp_port' => (int) ($data['smtp_port'] ?? 465),
            'smtp_ssl'  => (string) ($data['smtp_ssl'] ?? 'ssl'),
            'username'  => \trim((string) ($data['username'] ?? $email)),
            'password'  => (string) ($data['password'] ?? ''),
            'provider'  => \trim((string) ($data['provider'] ?? '')),
            'created_at' => \time(),
        ];

        if ($entry['imap_host'] === '') {
            throw new \RuntimeException('IMAP host is required');
        }
        if ($entry['smtp_host'] === '') {
            throw new \RuntimeException('SMTP host is required');
        }
        if ($entry['password'] === '') {
            throw new \RuntimeException('Password is required');
        }

        $json = \json_encode($entry, \JSON_UNESCAPED_SLASHES);
        $encrypted = $this->crypto->encrypt($json);
        $this->appConfig->setValueString('souvera_mail', $key, $encrypted);

        $this->logger->info('ExternalAccountService: account added', [
            'uid' => $uid, 'email' => $email,
        ]);

        $entry['id'] = $hash;
        unset($entry['password']);
        return $entry;
    }

    /**
     * Delete an external account.
     */
    public function delete(string $uid, string $id): void
    {
        $key = self::KEY_PREFIX . $uid . '.' . $id;
        if (!$this->appConfig->hasKey('souvera_mail', $key)) {
            throw new \RuntimeException('Account not found');
        }
        $this->appConfig->deleteKey('souvera_mail', $key);
        $this->logger->info('ExternalAccountService: account deleted', [
            'uid' => $uid, 'id' => $id,
        ]);
    }

    /**
     * Get full account data including password (for internal use only).
     *
     * @return array|null
     */
    public function getWithPassword(string $uid, string $id): ?array
    {
        $key = self::KEY_PREFIX . $uid . '.' . $id;
        if (!$this->appConfig->hasKey('souvera_mail', $key)) return null;
        try {
            $encrypted = $this->appConfig->getValueString('souvera_mail', $key, '');
            if ($encrypted === '') return null;
            $decrypted = $this->crypto->decrypt($encrypted);
            $data = \json_decode($decrypted, true);
            return \is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Test IMAP connection for an account.
     *
     * @return array{ok: bool, error?: string, folders?: int}
     */
    public function testImap(array $account): array
    {
        if (!\function_exists('imap_open')) {
            return ['ok' => false, 'error' => 'PHP IMAP extension is not installed'];
        }

        $ssl = ($account['imap_ssl'] ?? 'ssl') === 'none' ? 'novalidate-cert' : 'ssl';
        $mailbox = '{' . $account['imap_host'] . ':' . $account['imap_port'] . '/imap/' . $ssl . '}INBOX';

        try {
            $conn = @\imap_open($mailbox, $account['username'], $account['password'], 0, 1, [
                'DISABLE_AUTHENTICATOR' => 'GSSAPI',
            ]);
            if ($conn === false) {
                $err = \imap_last_error() ?: 'Unknown IMAP error';
                return ['ok' => false, 'error' => $err];
            }
            $folders = \imap_list($conn, '{' . $account['imap_host'] . '}', '*');
            \imap_close($conn);
            return ['ok' => true, 'folders' => \is_array($folders) ? \count($folders) : 0];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
