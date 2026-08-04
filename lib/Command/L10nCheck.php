<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\L10nService;
use OCP\App\IAppManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic: show which translation catalog the v2 UI would load for a
 * given Nextcloud user, and whether the expected files exist on disk.
 *
 * When to run
 * -----------
 * A user reports "the UI is still in English". First verify the version the
 * instance actually runs (the self-updater only pulls GitHub RELEASES —
 * plain branch pushes are never installed!). Then run:
 *
 *     occ souvera_mail:l10n:check joerg
 *
 * and compare the reported `resolved_lang` / `catalog` / `entries` against
 * what is expected. Typical causes, in order of likelihood:
 *
 *   1. The app is an OLD release (missing the inline-injection fix) —
 *      check `occ souvera_mail:status` / info.xml version first.
 *   2. l10n/<lang>.json is missing on the instance (deploy sync excluded
 *      the l10n/ directory, or the file was renamed to de_DE.json etc.).
 *   3. The user's Nextcloud language is not de/de_DE at all — then the
 *      whole NC UI is English, not just the mail app.
 *
 * Read-only, no side effects.
 */
class L10nCheck extends Command
{
    public function __construct(
        private IAppManager $appManager,
        private \OCP\IUserManager $userManager,
        private L10nService $l10nService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:l10n:check')
            ->setDescription('Show which translation catalog the v2 UI resolves for a user')
            ->addArgument('uid', InputArgument::OPTIONAL, 'Nextcloud user id — defaults to the instance default language', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) ($input->getArgument('uid') ?? '');
        // Personal user language is authoritative (L10nService::resolveLanguage).
        $lang = $this->l10nService->resolveLanguage();
        if ($uid !== '') {
            $user = $this->userManager->get($uid);
            if ($user === null) {
                $output->writeln("<error>User '{$uid}' does not exist.</error>");
                return 1;
            }
            $userLang = (string) $user->getUID();
            $userLang = (string) \OCP\Server::get(\OCP\IConfig::class)
                ->getUserValue($userLang, 'core', 'lang', '');
            if ($userLang !== '') {
                $lang = $userLang;
            }
        }
        $langShort = \substr($lang, 0, 2);
        $appPath = $this->appManager->getAppPath('souvera_mail');

        $output->writeln("<info>Translation resolution for v2 UI</info>");
        $output->writeln("  Nextcloud language code : {$lang}");
        $output->writeln("  short form               : {$langShort}");
        $output->writeln("  app path                 : " . ($appPath ?? '(not found)'));

        if ($appPath === null) {
            $output->writeln('<error>App path not resolvable — cannot inspect catalogs.</error>');
            return 1;
        }

        $candidates = [
            "l10n/{$lang}.json",
            "l10n/{$langShort}.json",
            "js/l10n-{$langShort}.json",
        ];
        $found = null;
        $entries = 0;
        foreach ($candidates as $rel) {
            $path = $appPath . '/' . $rel;
            if (!\is_file($path)) {
                $output->writeln("  catalog {$rel}            : <comment>missing</comment>");
                continue;
            }
            $raw = \file_get_contents($path);
            $parsed = $raw !== false ? \json_decode($raw, true) : null;
            $count = (\is_array($parsed) && isset($parsed['translations']) && \is_array($parsed['translations']))
                ? \count($parsed['translations'])
                : 0;
            if ($found === null && $count > 0) {
                $found = $rel;
                $entries = $count;
            }
            $output->writeln("  catalog {$rel}            : <info>found</info> ({$count} entries)");
        }

        if ($found === null) {
            $output->writeln('');
            $output->writeln('<error>No usable catalog found — the v2 UI falls back to English.</error>');
            $output->writeln('<comment>Fixes:</comment>');
            $output->writeln('  • Verify the app version (self-updater only installs GitHub RELEASES):');
            $output->writeln('      occ souvera_mail:status');
            $output->writeln('  • Ensure the l10n/ directory is part of the deploy sync.');
            $output->writeln('  • For NC languages like de_DE: l10n/de.json covers them (short form).');
            return 2;
        }

        $output->writeln('');
        $output->writeln("  <info>used catalog</info>          : {$found} ({$entries} entries)");
        if ($lang === 'en') {
            $output->writeln('');
            $output->writeln('<error>The Nextcloud language is English ("en") — the v2 UI shows English BY DESIGN.</error>');
            $output->writeln('<comment>This is not a translation bug: the app resolves the installed language correctly.</comment>');
            $output->writeln('<comment>To get German, set the language on the instance:</comment>');
            $output->writeln('  • Instance default language:  occ config:system:set default_language --value de');
            $output->writeln('  • Force German for everyone:  occ config:system:set forced_language --value de');
            $output->writeln('  • Single user:                occ user:setting <uid> core lang de');
            $output->writeln('  • Verify per user:            occ souvera_mail:l10n:check <uid>');
            return 0;
        }
        $output->writeln('');
        if ($entries >= 300) {
            $output->writeln('<info>OK — the v2 UI will use this catalog.</info>');
            return 0;
        }
        $output->writeln('<comment>Catalog exists but looks incomplete — if the UI still shows English, '
            . 'check the browser console for a blocked inline script (CSP) and verify the deployed version.</comment>');
        return 0;
    }
}
