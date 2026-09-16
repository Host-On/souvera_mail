<?php

declare(strict_types=1);

/**
 * Souvera Mail — occ souvera_mail:diag:jmap [email]
 *
 * Durchleuchtet die Kette Webmail → NC-Backend → Stalwart-JMAP Station
 * für Station und nennt die exakt fehlschlagende Stelle:
 *
 *   [1] Stalwart-API-URL (System-Config)
 *   [2] Admin-Credentials
 *   [3] GET /jmap/session als Admin (+ Server-Header, Capabilities)
 *   [4] accountId-Auflösung für den User
 *   [5] Bearer/OIDC-Auflösung für den User
 *   [6] Mailbox/get als User (echter JMAP-Roundtrip)
 *
 * Bei [3] wird zusätzlich der `Server`-Response-Header ausgegeben — daraus
 * lässt sich die laufende Stalwart-Version ablesen (relevant für die
 * Frage „neue Stalwart-JMAP / 0.16.22-Kompatibilität").
 */

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DiagJmap extends Command {
    public function __construct(
        private IConfig $config,
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_mail:diag:jmap')
            ->setDescription('Diagnose: Webmail → NC-Backend → Stalwart-JMAP Station für Station testen')
            ->addArgument('email', InputArgument::OPTIONAL, 'E-Mail-Adresse des zu testenden Users (für die User-Pfade 4–6)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $fail = 0;

        // [1] API-URL
        $apiUrl = $this->stalwart->getApiUrl();
        $output->writeln('[1] Stalwart-API-URL: ' . ($apiUrl ?? 'NICHT KONFIGURIERT (souvera_central.stalwart_api_url)'));
        if ($apiUrl === null) {
            $output->writeln('    → ENDE: ohne URL ist kein JMAP möglich.');
            return 1;
        }

        // [2] Admin-Credentials
        $creds = $this->stalwart->getAdminCredentials();
        $output->writeln('[2] Admin-Credentials: ' . ($creds !== null ? 'gesetzt (' . $creds[0] . ')' : 'FEHLEN (souvera_central.stalwart_admin_user/password)'));

        // [3] JMAP-Session als Admin — inkl. Server-Header (Stalwart-Version!)
        $session = null;
        try {
            $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
            $headers = ['Accept' => 'application/json'];
            if ($creds !== null) {
                $headers['Authorization'] = 'Basic ' . \base64_encode($creds[0] . ':' . $creds[1]);
            }
            $resp = $client->get($apiUrl . StalwartAdminService::SESSION_PATH, [
                'headers' => $headers, 'timeout' => 15, 'connect_timeout' => 10, 'http_errors' => false,
            ]);
            $code = $resp->getStatusCode();
            $serverHeader = (string) $resp->getHeader('Server');
            $body = \json_decode((string) $resp->getBody(), true);
            $output->writeln('[3] JMAP-Session als Admin: HTTP ' . $code
                . ($serverHeader !== '' ? ' — Server: ' . $serverHeader : ''));
            if ($code === 200 && \is_array($body)) {
                $session = $body;
                $caps = \array_keys($body['capabilities'] ?? []);
                $output->writeln('    Capabilities: ' . \implode(', ', $caps));
                if (isset($body['primaryAccounts'])) {
                    $output->writeln('    primaryAccounts: ' . \json_encode($body['primaryAccounts'], JSON_UNESCAPED_SLASHES));
                }
            } else {
                $output->writeln('    Body: ' . \mb_substr((string) $resp->getBody(), 0, 300));
                $fail = 1;
            }
        } catch (\Throwable $e) {
            $output->writeln('[3] JMAP-Session als Admin: FEHLER — ' . $e->getMessage());
            $fail = 1;
        }

        $email = \trim((string) $input->getArgument('email'));
        if ($email === '') {
            $output->writeln('(ohne email-Argument: User-Pfade [4]-[6] übersprungen — für den vollen Test eine E-Mail-Adresse mitgeben)');
            return $fail;
        }

        // [4] accountId
        $accountId = null;
        try {
            $accountId = $this->userContext->resolveAccountId($email);
            $output->writeln('[4] accountId für "' . $email . '": ' . $accountId);
        } catch (\Throwable $e) {
            $output->writeln('[4] accountId-Auflösung FEHLGESCHLAGEN: ' . $e->getMessage());
            $fail = 1;
        }

        // [5] Bearer
        $bearer = null;
        try {
            $bearer = $this->userContext->resolveBearer($email);
            $output->writeln('[5] Bearer/OIDC für "' . $email . '": ' . (\strlen($bearer) > 0 ? 'OK (' . \strlen($bearer) . ' Zeichen)' : 'LEER'));
        } catch (\Throwable $e) {
            $output->writeln('[5] Bearer-Auflösung FEHLGESCHLAGEN: ' . $e->getMessage());
            $fail = 1;
        }

        // [6] Echter JMAP-Roundtrip
        try {
            $result = $this->stalwart->jmapCall($bearer ?? '', [[
                'Mailbox/get', ['accountId' => $accountId ?? ''], 'd0',
            ]]);
            $list = $result['d0']['args']['list'] ?? [];
            $state = $result['d0']['args']['state'] ?? '?';
            $output->writeln('[6] Mailbox/get als User: OK — state=' . $state . ', ' . \count($list) . ' Ordner');
        } catch (\Throwable $e) {
            $output->writeln('[6] Mailbox/get FEHLGESCHLAGEN: ' . $e->getMessage());
            $fail = 1;
        }

        return $fail;
    }
}
