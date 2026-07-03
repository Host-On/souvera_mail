<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\OidcProviderService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic: for a given Nextcloud user id, dump EXACTLY the credentials
 * Snappymail would use to open the mailbox. Read-only, no side effects.
 *
 * When to run
 * -----------
 * A user reports "I see someone else's inbox" (rare but critical). Instead of
 * guessing whether the mismatch lives in Central's provisioning, our OIDC
 * bridge, or Nextcloud itself, run:
 *
 *     occ souvera_mail:whoami joerg
 *
 * and inspect the reported `resolved_email`. If it matches the user's OWN
 * mail address → the bug is elsewhere (session/cookie/impersonation trace).
 * If it matches someone ELSE'S mail address → Central set the wrong
 * `settings/email` (or `souvera_mail/email` override) for this uid.
 *
 * Output covers every stage of the {@see \OCA\SouveraMail\Util\EngineHelper::getSsoEmail}
 * cascade so the culprit is unambiguous.
 */
class Whoami extends Command
{
    public function __construct(
        private IUserManager $userManager,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private OidcProviderService $oidcProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:whoami')
            ->setDescription('Show what mail credentials Snappymail would resolve for a given Nextcloud user id')
            ->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user id (e.g. "joerg" — NOT the email)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a JSON object instead of a text table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) $input->getArgument('uid');
        $wantJson = (bool) $input->getOption('json');

        $user = $this->userManager->get($uid);
        if ($user === null) {
            $msg = "User '{$uid}' does not exist in Nextcloud.";
            if ($wantJson) {
                $output->writeln(\json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln("<error>{$msg}</error>");
                $output->writeln('Hint: run `occ user:list` to find the correct uid (Central may use a hash, not the email).');
            }
            return 1;
        }

        // The four sources the EngineHelper::getSsoEmail() cascade checks,
        // in order of precedence. We report ALL of them so a mismatch is
        // instantly visible.
        $customOverride = $this->userConfig->getValueString($uid, 'souvera_mail', 'email', '');
        $settingsEmail  = $this->userConfig->getValueString($uid, 'settings', 'email', '');
        $ncUserEmail    = (string) ($user->getEMailAddress() ?? '');
        $displayName    = (string) $user->getDisplayName();
        $lastLogin      = (int) $user->getLastLogin();
        $backend        = \method_exists($user, 'getBackendClassName')
            ? (string) $user->getBackendClassName()
            : 'unknown';

        // What Snappymail would actually pick — mirrors the exact cascade
        // logic in EngineHelper::getSsoEmail() so we do not drift.
        $resolvedEmail = $uid; // 4. fallback (uid itself)
        $resolvedFrom  = 'fallback:uid';
        if ($customOverride !== '') {
            $resolvedEmail = $customOverride;
            $resolvedFrom  = 'userconfig[souvera_mail/email]';
        } elseif ($settingsEmail !== '') {
            $resolvedEmail = $settingsEmail;
            $resolvedFrom  = 'userconfig[settings/email]';
        } elseif ($ncUserEmail !== '') {
            $resolvedEmail = $ncUserEmail;
            $resolvedFrom  = 'IUser::getEMailAddress()';
        }

        // Cross-consistency check: uid contains '@'? then compare local
        // parts. If the resolved email's localpart differs from the uid's,
        // that is a strong hint that Central set the wrong email.
        $warnings = [];
        if (\str_contains($uid, '@') && $resolvedEmail !== '' && $resolvedEmail !== $uid) {
            $warnings[] = "uid ('{$uid}') contains '@' AND resolved email ('{$resolvedEmail}') differs — mailbox mismatch risk";
        }
        if (\str_contains($uid, '@') === false
                && $resolvedEmail !== ''
                && \str_contains($resolvedEmail, '@')
                && \strtolower(\explode('@', $resolvedEmail, 2)[0]) !== \strtolower($uid)) {
            $warnings[] = "uid localpart ('{$uid}') differs from resolved email localpart ('"
                . \explode('@', $resolvedEmail, 2)[0]
                . "') — inspect Central's provisioning for this user";
        }

        // OIDC status snapshot — the OIDC access token is generated per uid,
        // so if it fails here it will fail during login too.
        $oidcAvail = $this->oidcProvider->isProviderAvailable();
        $oidcAutoLogin = $this->appConfig->getValueString('souvera_mail', 'autologin-oidc', '0') === '1';
        $oidcToken = null;
        $oidcErr = null;
        if ($oidcAvail && $oidcAutoLogin) {
            try {
                $tok = $this->oidcProvider->generateAccessToken($uid);
                $oidcToken = $tok !== null && $tok !== ''
                    ? '(present, ' . \strlen($tok) . ' chars)'
                    : '(none)';
            } catch (\Throwable $e) {
                $oidcErr = $e->getMessage();
            }
        }

        $snapshot = [
            'uid' => $uid,
            'display_name' => $displayName,
            'backend' => $backend,
            'last_login_ts' => $lastLogin,
            'sources' => [
                'userconfig[souvera_mail/email]' => $customOverride,
                'userconfig[settings/email]' => $settingsEmail,
                'IUser::getEMailAddress()' => $ncUserEmail,
                'fallback:uid' => $uid,
            ],
            'resolved_email' => $resolvedEmail,
            'resolved_from' => $resolvedFrom,
            'oidc' => [
                'provider_available' => $oidcAvail,
                'autologin_enabled' => $oidcAutoLogin,
                'access_token' => $oidcToken ?? '(not attempted)',
                'access_token_error' => $oidcErr,
            ],
            'warnings' => $warnings,
        ];

        if ($wantJson) {
            $output->writeln(\json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $warnings === [] ? 0 : 2;
        }

        $output->writeln("<info>Nextcloud user:</info> {$uid}    (display: {$displayName}, backend: {$backend})");
        $output->writeln('');
        $output->writeln('<info>Email cascade — first non-empty value wins:</info>');
        $output->writeln('  1. userconfig[souvera_mail/email] = ' . self::pretty($customOverride));
        $output->writeln('  2. userconfig[settings/email]     = ' . self::pretty($settingsEmail));
        $output->writeln('  3. IUser::getEMailAddress()       = ' . self::pretty($ncUserEmail));
        $output->writeln('  4. fallback: uid                  = ' . self::pretty($uid));
        $output->writeln('');
        $output->writeln("<info>Snappymail would use:</info> <comment>{$resolvedEmail}</comment>  ({$resolvedFrom})");
        $output->writeln('');
        $output->writeln('<info>OIDC status:</info>');
        $output->writeln('  provider available     = ' . ($oidcAvail ? '<info>yes</info>' : '<error>no</error>'));
        $output->writeln('  autologin-oidc enabled = ' . ($oidcAutoLogin ? '<info>yes</info>' : '<error>no</error>'));
        if ($oidcAvail && $oidcAutoLogin) {
            $output->writeln('  access-token for uid   = ' . ($oidcToken ?? '(not attempted)'));
            if ($oidcErr !== null) {
                $output->writeln('  access-token error     = <error>' . $oidcErr . '</error>');
            }
        }
        if ($warnings !== []) {
            $output->writeln('');
            $output->writeln('<comment>Warnings:</comment>');
            foreach ($warnings as $w) {
                $output->writeln('  ⚠ ' . $w);
            }
            $output->writeln('');
            $output->writeln('<comment>Common fixes:</comment>');
            $output->writeln('  • Central set the wrong email:');
            $output->writeln("      occ user:setting {$uid} settings email <correct-address>");
            $output->writeln('  • Souvera Mail per-user override (if set):');
            $output->writeln("      occ user:setting {$uid} souvera_mail email --delete");
            $output->writeln('');
            return 2;
        }

        return 0;
    }

    private static function pretty(string $v): string
    {
        return $v === '' ? '<comment>(empty)</comment>' : $v;
    }
}
