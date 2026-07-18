<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command\External;

use OCA\SouveraMail\Service\ExternalAccountsConfig;
use OCA\SouveraMail\Service\ExternalAccountsFailGuard;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List external mail accounts currently attached by users.
 *
 * Reads directly from Snappymail's per-user JSON storage in
 * `appdata_souvera_mail/_data_/_default_/storage/*` — this is the
 * canonical location for `AdditionalAccount`-encoded entries.
 *
 * IMPORTANT: This command only shows METADATA (email, name, added).
 * It NEVER decrypts the stored password (that requires the user's
 * session token which we do not have in a CLI context by design).
 *
 * Usage:
 *   occ souvera_mail:external:list             # every user
 *   occ souvera_mail:external:list <uid>       # single user
 */
final class ListAccounts extends Command
{
    public function __construct(
        private ExternalAccountsConfig $config,
        private ExternalAccountsFailGuard $guard,
        private IUserManager $userManager,
        private IDBConnection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('souvera_mail:external:list')
            ->setDescription('List external mail accounts attached by users (metadata only, no passwords)')
            ->addArgument('uid', InputArgument::OPTIONAL, 'Limit to a specific user id')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit as JSON')
            ->addOption('deactivated-only', null, InputOption::VALUE_NONE,
                'Only list accounts currently in auto-deactivated state (3× SMTP fail guard tripped)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uidFilter = $input->getArgument('uid');
        $jsonMode = (bool) $input->getOption('json');

        $rows = [];
        if ((bool) $input->getOption('deactivated-only')) {
            foreach ($this->guard->listDeactivated() as $entry) {
                if ($uidFilter !== null && $entry['uid'] !== $uidFilter) {
                    continue;
                }
                $rows[] = [
                    'uid'            => $entry['uid'],
                    'email'          => '(sha1 ' . $entry['email_hash'] . ')',
                    'name'           => '',
                    'deactivated_at' => \gmdate('c', $entry['deactivated_at']),
                    'fail_count'     => $entry['count'],
                ];
            }
        } else {
            $rows = $this->collectAllAccounts($uidFilter);
        }

        if ($jsonMode) {
            $output->writeln(\json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            return 0;
        }

        if (empty($rows)) {
            $output->writeln('<comment>No external accounts found.</comment>');
            return 0;
        }
        $output->writeln(\sprintf('<info>%-24s %-40s %-20s %s</info>',
            'user', 'email', 'display_name', 'flag'));
        $output->writeln(\str_repeat('-', 100));
        foreach ($rows as $r) {
            $output->writeln(\sprintf('%-24s %-40s %-20s %s',
                $r['uid'],
                $r['email'] ?? '',
                $r['name'] ?? '',
                !empty($r['deactivated_at']) ? '⚠ deactivated ' . $r['deactivated_at'] : ''
            ));
        }
        return 0;
    }

    /**
     * Walks the Snappymail per-user storage directory and pulls
     * `additionalaccounts.json` entries. This bypasses the engine
     * lifecycle so we can enumerate without a logged-in session.
     *
     * @return list<array<string,mixed>>
     */
    private function collectAllAccounts(?string $uidFilter): array
    {
        $rows = [];
        // The engine writes each user's storage under a hash of their
        // main-account email — enumerating requires walking the tree.
        // APP_PRIVATE_DATA is only available inside the engine bootstrap;
        // in a CLI context we resolve it via the standard NC data dir.
        try {
            $dataDir = \rtrim(\trim(\OCP\Server::get(\OCP\IConfig::class)
                ->getSystemValue('datadirectory', '')), '\\/');
        } catch (\Throwable) {
            return $rows;
        }
        $root = $dataDir . '/appdata_souvera_mail/_data_/_default_/storage';
        if (!\is_dir($root)) {
            return $rows;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getFilename() !== 'additionalaccounts') { continue; }
            $raw = @\file_get_contents($file->getPathname());
            if ($raw === false) { continue; }
            $data = @\json_decode($raw, true);
            if (!\is_array($data)) { continue; }
            // The parent folder name is the user-hash — not the uid.
            // The uid can be reconstructed from the linked identities
            // folder which stores the OIDC preferred_username. As a
            // support-focused command we accept "unknown_<hash>" when
            // we can't map back to a uid.
            $userHash = \basename(\dirname($file->getPath()));
            $uid = $this->uidFromStorageHash($userHash) ?? ('unknown_' . $userHash);
            if ($uidFilter !== null && $uid !== $uidFilter) { continue; }
            foreach ($data as $entry) {
                if (!\is_array($entry)) { continue; }
                $email = (string) ($entry['Email'] ?? $entry['email'] ?? '');
                $name  = (string) ($entry['Name']  ?? $entry['name']  ?? '');
                $status = $this->guard->status($uid, $email);
                $rows[] = [
                    'uid'            => $uid,
                    'email'          => $email,
                    'name'           => $name,
                    'deactivated_at' => $status['deactivated']
                        ? \gmdate('c', $status['deactivated_at']) : '',
                    'fail_count'     => $status['count'],
                ];
            }
        }
        \usort($rows, static fn($a, $b) => \strcmp((string)$a['uid'], (string)$b['uid']));
        return $rows;
    }

    /** Best-effort reverse-lookup of the NC uid for a storage-dir hash. */
    private function uidFromStorageHash(string $hash): ?string
    {
        // We rely on the fact that Souvera Mail stores an oc_appconfig
        // row for every provisioned user with the mapping (see
        // AppPasswordService::hashForUser). Cheap lookup by prefix.
        try {
            $q = $this->db->getQueryBuilder();
            $q->select('configkey', 'configvalue')
                ->from('appconfig')
                ->where($q->expr()->eq('appid', $q->createNamedParameter('souvera_mail')))
                ->andWhere($q->expr()->like('configkey', $q->createNamedParameter('user_hash.%')));
            $res = $q->executeQuery();
            while ($row = $res->fetch()) {
                if ((string) $row['configvalue'] === $hash) {
                    $parts = \explode('.', (string) $row['configkey'], 2);
                    return $parts[1] ?? null;
                }
            }
            $res->closeCursor();
        } catch (\Throwable) {
            // Table may not exist yet on cold installs; that's fine.
        }
        return null;
    }
}
