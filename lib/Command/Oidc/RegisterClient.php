<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command\Oidc;

use OCA\SouveraMail\Service\OidcProviderService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers Souvera Mail as an OIDC client inside the H2CK/oidc Nextcloud
 * OIDC Provider app via that app's own `occ oidc:create` command. The
 * generated client_id + client_secret are persisted in Souvera Mail's
 * app-config (so subsequent `TokenGenerationRequestEvent` dispatches resolve
 * to the same client) and optionally dumped to a deploy-supplied secret file.
 *
 * Idempotent: when a client of the requested name already exists in
 * H2CK/oidc and Souvera Mail's app-config already holds the resulting
 * `oidc-client-id`, the command exits with a no-op success unless `--force`
 * is supplied to rotate the secret.
 */
class RegisterClient extends Command
{
    private const APP_ID = 'souvera_mail';

    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private OidcProviderService $oidcProvider,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:oidc:register-client')
            ->setDescription('Register Souvera Mail as an OIDC client inside the Nextcloud OIDC Provider (H2CK/oidc)')
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'OIDC client identifier (default: "souvera_mail")',
                OidcProviderService::SOUVERA_MAIL_CLIENT_NAME_DEFAULT,
            )
            ->addOption(
                'redirect-uri',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'OAuth redirect URI (repeat to add several). Defaults to <NC>/index.php/apps/souvera_mail/',
            )
            ->addOption(
                'secret-out',
                null,
                InputOption::VALUE_REQUIRED,
                'Write the generated client_secret to this file path (mode 0600), e.g. /etc/souvera_mail/oidc.secret',
            )
            ->addOption(
                'token-lifetime',
                null,
                InputOption::VALUE_REQUIRED,
                'Access-token lifetime in seconds (defaults to H2CK/oidc default, typically 1800)',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Recreate the client and rotate the client_secret even if a souvera_mail client already exists',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print the actions that would be taken without modifying anything',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit a single machine-readable JSON object to stdout instead of human-readable text',
            )
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRedirectUris(InputInterface $input): array
    {
        $supplied = $input->getOption('redirect-uri');
        if (\is_array($supplied) && $supplied !== []) {
            return $supplied;
        }
        // Default to the souvera_mail index page so a future browser flow (if ever needed) works out of the box
        $default = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('souvera_mail.page.index')
        );
        return [$default];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $clientName = (string) $input->getOption('name');
        $force = (bool) $input->getOption('force');
        $secretOut = $input->getOption('secret-out');
        $tokenLifetime = $input->getOption('token-lifetime');
        $redirectUris = $this->buildRedirectUris($input);

        $report = [
            'command' => 'souvera_mail:oidc:register-client',
            'dry_run' => $dryRun,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'actions' => [],
            'status' => 'ok',
        ];

        // 1. Preflight — H2CK/oidc must be installed + enabled
        if (!$this->appManager->isInstalled(OidcProviderService::OIDC_APP_ID)
            || !$this->appManager->isEnabledForUser(OidcProviderService::OIDC_APP_ID)
        ) {
            return $this->fail(
                $output,
                $jsonMode,
                $report,
                'H2CK/oidc app is not installed/enabled. Run: occ app:install oidc && occ app:enable oidc',
            );
        }

        // 2. Locate the H2CK/oidc CLI command
        $application = $this->getApplication();
        if ($application === null) {
            return $this->fail($output, $jsonMode, $report, 'Symfony console application unavailable');
        }
        if (!$application->has('oidc:create')) {
            return $this->fail(
                $output,
                $jsonMode,
                $report,
                'H2CK/oidc oidc:create command not found — version mismatch (need 1.17+)?',
            );
        }

        // 3. Idempotency — is souvera_mail already registered?
        $existingClient = $this->appConfig->getValueString(self::APP_ID, OidcProviderService::SOUVERA_MAIL_CLIENT_KEY, '');
        if ($existingClient !== '' && !$force) {
            $report['actions'][] = ['skip' => "Client '{$existingClient}' already registered (use --force to rotate)"];
            return $this->finalize($output, $jsonMode, $report);
        }

        if ($dryRun) {
            $report['actions'][] = ['would_invoke' => 'oidc:create', 'name' => $clientName, 'redirect_uris' => $redirectUris, 'token_type' => 'jwt'];
            $report['actions'][] = ['would_persist' => self::APP_ID . '/' . OidcProviderService::SOUVERA_MAIL_CLIENT_KEY];
            if (\is_string($secretOut) && $secretOut !== '') {
                $report['actions'][] = ['would_write_secret_file' => $secretOut];
            }
            return $this->finalize($output, $jsonMode, $report);
        }

        // 4. Invoke H2CK/oidc's oidc:create. The exact CLI signature (H2CK 1.17+):
        //   php occ oidc:create <name> <redirect_uri> [<redirect_uri> …]
        //         [--algorithm RS256|HS256] [--flow code|"code id_token"]
        //         [--type confidential|public] [--token_type opaque|jwt]
        //         [--allowed_scopes "openid profile email"] [--email_regex "..."]
        //         [--client_id <id>] [--client_secret <secret>] [--resource_url <url>]
        // The command prints the created client as a pretty-printed JSON object
        // (Client::jsonSerialize()) on success, with the keys client_id / client_secret.
        // We pass our redirect URIs as the positional IS_ARRAY argument and JWT
        // access tokens are requested per-client via --token_type=jwt so we never
        // rely on H2CK's global default_token_type having been flipped.
        $createInputArgs = [
            'name' => $clientName,
            'redirect_uris' => $redirectUris,
            '--type' => 'confidential',
            '--token_type' => 'jwt',
            '--flow' => 'code',
            '--algorithm' => 'RS256',
        ];

        $bufferedOutput = new BufferedOutput();
        try {
            $createCommand = $application->find('oidc:create');
            $returnCode = $createCommand->run(new ArrayInput($createInputArgs), $bufferedOutput);
        } catch (\Throwable $e) {
            $report['raw_invocation_args'] = $createInputArgs;
            return $this->fail($output, $jsonMode, $report, 'oidc:create dispatch threw: ' . $e->getMessage());
        }

        $createOutput = $bufferedOutput->fetch();
        if ($returnCode !== Command::SUCCESS) {
            $report['raw_oidc_create_output'] = $createOutput;
            $report['raw_invocation_args'] = $createInputArgs;
            return $this->fail($output, $jsonMode, $report, 'oidc:create returned non-zero — raw output preserved in report');
        }

        // 5. Parse the JSON object emitted by oidc:create. H2CK 1.14+ outputs
        //    json_encode($client, JSON_PRETTY_PRINT) where the keys are exactly
        //    "client_id" and "client_secret" (see Client::jsonSerialize in
        //    H2CK/oidc 1.17+). Older releases used a "Client ID: <x>" /
        //    "Client Secret: <y>" text format, which we keep as a defensive
        //    fallback so the wrapper still works against pre-JSON H2CK builds.
        $clientId = null;
        $clientSecret = null;
        $decoded = \json_decode($createOutput, true);
        if (\is_array($decoded)) {
            $clientId = $decoded['client_id'] ?? $decoded['clientIdentifier'] ?? null;
            $clientSecret = $decoded['client_secret'] ?? $decoded['secret'] ?? null;
        }
        if ($clientId === null || $clientSecret === null) {
            if (\preg_match('/(?:client_id|Client\s*ID)[:\s"]+([A-Za-z0-9!"#$%&\'()*+,\-./;<=>?@\[\\\\\]^_`{|}~]+)/', $createOutput, $idM) === 1) {
                $clientId = $idM[1];
            }
            if (\preg_match('/(?:client_secret|Client\s*Secret)[:\s"]+([A-Za-z0-9!"#$%&\'()*+,\-./;<=>?@\[\\\\\]^_`{|}~]+)/', $createOutput, $secM) === 1) {
                $clientSecret = $secM[1];
            }
        }
        if (!\is_string($clientId) || $clientId === '' || !\is_string($clientSecret) || $clientSecret === '') {
            $report['raw_oidc_create_output'] = $createOutput;
            $report['raw_invocation_args'] = $createInputArgs;
            return $this->fail(
                $output,
                $jsonMode,
                $report,
                'Could not parse client_id/client_secret from oidc:create output — raw output preserved in report',
            );
        }

        // 6. Persist
        $this->appConfig->setValueString(self::APP_ID, OidcProviderService::SOUVERA_MAIL_CLIENT_KEY, $clientId);
        $report['actions'][] = ['registered' => $clientName, 'client_id' => $clientId];

        // 7. Optional secret file
        if (\is_string($secretOut) && $secretOut !== '') {
            $bytesWritten = @\file_put_contents($secretOut, $clientSecret . "\n");
            if ($bytesWritten === false) {
                $report['actions'][] = ['warning' => "could not write secret to {$secretOut}"];
            } else {
                @\chmod($secretOut, 0600);
                $report['actions'][] = ['wrote_secret_file' => $secretOut];
            }
        }

        // 8. Apply --token-lifetime app-globally (H2CK/oidc setting)
        if (\is_string($tokenLifetime) && \ctype_digit($tokenLifetime) && $application->has('config:app:set')) {
            try {
                $cfgCmd = $application->find('config:app:set');
                $cfgCmd->run(new ArrayInput([
                    'app' => 'oidc',
                    'key' => 'expire_time',
                    '--value' => $tokenLifetime,
                ]), new BufferedOutput());
                $report['actions'][] = ['set_oidc_expire_time' => (int) $tokenLifetime];
            } catch (\Throwable $e) {
                $report['actions'][] = ['warning' => 'could not set oidc/expire_time: ' . $e->getMessage()];
            }
        }

        // 9. Ensure default_token_type = jwt (RFC 9068)
        if ($application->has('config:app:set')) {
            try {
                $cfgCmd = $application->find('config:app:set');
                $cfgCmd->run(new ArrayInput([
                    'app' => 'oidc',
                    'key' => 'default_token_type',
                    '--value' => 'jwt',
                ]), new BufferedOutput());
                $report['actions'][] = ['set_oidc_default_token_type' => 'jwt'];
            } catch (\Throwable $e) {
                $report['actions'][] = ['warning' => 'could not enforce JWT access tokens: ' . $e->getMessage()];
            }
        }

        // 10. Invalidate any cached token (forces fresh issuance with new client)
        $this->oidcProvider->invalidate();

        return $this->finalize($output, $jsonMode, $report);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function fail(OutputInterface $output, bool $jsonMode, array $report, string $message): int
    {
        $report['status'] = 'error';
        $report['message'] = $message;
        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }
        return Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function finalize(OutputInterface $output, bool $jsonMode, array $report): int
    {
        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }
        foreach ($report['actions'] as $action) {
            foreach ($action as $key => $value) {
                $valueStr = \is_array($value) ? \json_encode($value) : (string) $value;
                $output->writeln("  <info>{$key}</info> {$valueStr}");
            }
        }
        $output->writeln('<info>OIDC client registration complete (' . $report['client_name'] . ')</info>');
        return Command::SUCCESS;
    }
}
