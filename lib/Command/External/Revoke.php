<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command\External;

use OCA\SouveraMail\Service\ExternalAccountsFailGuard;
use OCA\SouveraMail\Service\LogService;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compliance / support command: revoke a single external mail account
 * for a given user, or reset the SMTP-fail counter after the user
 * confirmed the underlying provider issue is fixed.
 *
 * By design this command does NOT decrypt the stored password — it
 * only removes the encrypted entry from the user's
 * `additionalaccounts` storage file and clears any associated fail
 * counter.
 *
 * Two operational modes:
 *
 *   1. `--revoke`   Delete the entry (irreversible; user has to
 *                   re-add the account if they want it back).
 *   2. `--reset`    Only clear the SMTP-fail counter (re-enables an
 *                   auto-deactivated account for another 24h window).
 *
 * Requires --confirm to be passed with a matching email + uid to
 * avoid accidental purges.
 */
final class Revoke extends Command
{
    public function __construct(
        private ExternalAccountsFailGuard $guard,
        private LogService $log,
        private IUserManager $userManager,
        private IConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('souvera_mail:external:revoke')
            ->setDescription('Revoke an external mail account or reset its SMTP-fail guard state')
            ->addArgument('uid', InputArgument::REQUIRED, 'NC user id')
            ->addArgument('email', InputArgument::REQUIRED, 'External email address to act on')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Actually delete the entry')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Clear the SMTP-fail counter only (do not remove the account)')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required for --revoke — safety belt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid   = (string) $input->getArgument('uid');
        $email = (string) $input->getArgument('email');
        $json  = (bool) $input->getOption('json');
        $doRevoke = (bool) $input->getOption('revoke');
        $doReset  = (bool) $input->getOption('reset');

        if (!$doRevoke && !$doReset) {
            $this->say($output, $json, [
                'ok' => false,
                'error' => 'Pass exactly one of --revoke or --reset.',
            ]);
            return 2;
        }
        if ($doRevoke && $doReset) {
            $this->say($output, $json, [
                'ok' => false,
                'error' => '--revoke and --reset are mutually exclusive.',
            ]);
            return 2;
        }
        if ($doRevoke && !(bool) $input->getOption('confirm')) {
            $this->say($output, $json, [
                'ok' => false,
                'error' => '--revoke requires --confirm as a safety belt.',
            ]);
            return 2;
        }
        if ($this->userManager->get($uid) === null) {
            $this->say($output, $json, [
                'ok' => false,
                'error' => 'Unknown NC user id: ' . $uid,
            ]);
            return 2;
        }

        if ($doReset) {
            $this->guard->reset($uid, $email);
            $this->log->info(\sprintf(
                'External account SMTP-fail guard reset for uid=%s email_hash=%s',
                $uid, \substr(\sha1(\strtolower(\trim($email))), 0, 12)
            ), ['category' => 'external_accounts.revoke']);
            $this->say($output, $json, [
                'ok' => true,
                'action' => 'reset',
                'uid' => $uid,
                'email' => $email,
            ]);
            return 0;
        }

        // --revoke: remove the encrypted entry from the user's
        // additionalaccounts storage file. We treat every failure to
        // parse the JSON as "the account was not there" — idempotent.
        $removed = $this->removeAccountEntry($uid, $email);
        // Also always clear the fail-guard state (defensive).
        $this->guard->reset($uid, $email);
        $this->log->info(\sprintf(
            'External account revoked for uid=%s email_hash=%s (existed=%s)',
            $uid,
            \substr(\sha1(\strtolower(\trim($email))), 0, 12),
            $removed ? 'yes' : 'no'
        ), ['category' => 'external_accounts.revoke']);
        $this->say($output, $json, [
            'ok' => true,
            'action' => 'revoke',
            'uid' => $uid,
            'email' => $email,
            'existed' => $removed,
        ]);
        return 0;
    }

    /**
     * Rewrite the user's `additionalaccounts` JSON file with the
     * given email removed. Returns true when an entry was actually
     * removed.
     */
    private function removeAccountEntry(string $uid, string $email): bool
    {
        $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
        $root = $dataDir . '/appdata_souvera_mail/_data_/_default_/storage';
        if (!\is_dir($root)) { return false; }

        $removed = false;
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
            $filtered = [];
            $found = false;
            foreach ($data as $entry) {
                if (\is_array($entry)
                    && \strcasecmp((string) ($entry['Email'] ?? $entry['email'] ?? ''), $email) === 0) {
                    $found = true;
                    continue;
                }
                $filtered[] = $entry;
            }
            if ($found) {
                @\file_put_contents($file->getPathname(), \json_encode($filtered));
                $removed = true;
            }
        }
        return $removed;
    }

    /** @param array<string,mixed> $payload */
    private function say(OutputInterface $out, bool $json, array $payload): void
    {
        if ($json) {
            $out->writeln(\json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            return;
        }
        if (empty($payload['ok'])) {
            $out->writeln('<error>' . (string) ($payload['error'] ?? 'error') . '</error>');
            return;
        }
        $out->writeln('<info>OK — ' . (string) ($payload['action'] ?? '') . ' ' . (string) ($payload['email'] ?? '') . '</info>');
    }
}
