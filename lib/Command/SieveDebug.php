<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\SieveScriptService;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only Sieve diagnostics for one user — used to debug "filters show
 * in the UI but are not applied" reports.
 *
 *   occ souvera_mail:sieve:debug <uid>
 *
 * Prints: resolved Stalwart account, every script (name, active, enabled,
 * body length + head), the disabled list and the merged main script body.
 */
class SieveDebug extends Command
{
    public function __construct(
        private SieveScriptService $sieve,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwart,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:sieve:debug')
            ->setDescription('Diagnose Sieve filter state for a user (read-only)')
            ->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) $input->getArgument('uid');
        $user = $this->userManager->get($uid);
        if ($user === null) {
            $output->writeln("<error>User '{$uid}' does not exist.</error>");
            return 1;
        }

        $output->writeln("== Sieve debug for '{$uid}' ==");
        try {
            $accountId = $this->userContext->resolveAccountId($uid);
            $output->writeln('accountId: ' . $accountId);
        } catch (\Throwable $e) {
            $output->writeln('<error>resolveAccountId failed: ' . $e->getMessage() . '</error>');
        }
        try {
            $output->writeln('email: ' . ($this->userContext->resolveEmail($uid) ?? '(none)'));
        } catch (\Throwable $e) {
            $output->writeln('<error>resolveEmail failed: ' . $e->getMessage() . '</error>');
        }

        if (!$this->sieve->isAvailable()) {
            $output->writeln('<error>Sieve is not available (Stalwart not configured or context unavailable).</error>');
            return 1;
        }

        try {
            $result = $this->sieve->listScriptsWithBodies($uid);
        } catch (\Throwable $e) {
            $output->writeln('<error>listScriptsWithBodies failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln('disabled: ' . \json_encode($this->sieve->getDisabledFilters($uid), JSON_UNESCAPED_SLASHES));
        $output->writeln('--- scripts ---');
        foreach ($result['scripts'] as $s) {
            $body = (string) ($s['body'] ?? '');
            $head = $body !== '' ? \str_replace("\n", ' ', \mb_substr($body, 0, 140)) : '(EMPTY BODY)';
            $output->writeln(\sprintf(
                '- name=%s id=%s active=%s enabled=%s isMain=%s bodyLen=%d | %s',
                $s['name'],
                $s['id'],
                $s['isActive'] ? 'yes' : 'no',
                ($s['enabled'] ?? false) ? 'yes' : 'no',
                ($s['isMain'] ?? false) ? 'yes' : 'no',
                \strlen($body),
                $head,
            ));
        }
        $output->writeln('--- mailboxes (fileinto targets must EXIST) ---');
        try {
            $accountId = $this->userContext->resolveAccountId($uid);
            $bearer = $this->userContext->resolveBearer($uid);
            $response = $this->stalwart->jmapCall(
                $bearer,
                [['Mailbox/get', ['accountId' => $accountId, 'properties' => ['id', 'name', 'role']], 'm0']],
                ['urn:ietf:params:jmap:mail']
            );
            $list = (array) ($this->stalwart->extractMethodResponse($response, 'Mailbox/get')['list'] ?? []);
            foreach ($list as $mb) {
                $output->writeln('  - ' . ($mb['name'] ?? '?') . ' (role=' . ($mb['role'] ?? '-') . ')');
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Mailbox/get failed: ' . $e->getMessage() . '</error>');
        }

        $output->writeln('--- merged main script (what Stalwart executes when active) ---');
        foreach ($result['scripts'] as $s) {
            if (!($s['isMain'] ?? false)) {
                continue;
            }
            $output->writeln((string) ($s['body'] ?? ''));
        }

        return 0;
    }
}
