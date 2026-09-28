<?php

declare(strict_types=1);

/**
 * Souvera Mail — occ souvera_mail:diag:sieve [email]
 *
 * Diagnostiziert die komplette Sieve-Kette eines Users:
 *
 *   [1] Bearer/OIDC-Auflösung
 *   [2] JMAP accountId
 *   [3] SieveScript/get (alle Scripts mit name/isActive/blobId)
 *   [4] Der AKTIVE Script — Inhalt (Kopf, 15 Zeilen)
 *   [5] Capabilities-Verifikation: Validate-Roundtrip eines Scripts mit
 *       require ["imap4flags","fileinto","vacation"] (zeigt, welche
 *       Capabilities Stalwart wirklich akzeptiert)
 *   [6] Disabled-Filters-User-Pref
 *
 * Run: occ souvera_mail:diag:sieve <uid>
 */

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\SieveScriptService;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DiagSieve extends Command {
    public function __construct(
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwart,
        private SieveScriptService $sieve,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('souvera_mail:diag:sieve')
            ->setDescription('Sieve-Kettendiagnose: Scripts, Aktivierung, Capabilities für einen User')
            ->addArgument('email', InputArgument::REQUIRED, 'User-Id oder E-Mail-Adresse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uid = (string) $input->getArgument('email');
        $user = $this->userManager->get($uid) ?? $this->userManager->getByEmail($uid)[0] ?? null;
        if ($user === null) {
            $output->writeln('<error>User nicht gefunden: ' . $uid . '</error>');
            return Command::FAILURE;
        }
        $uid = $user->getUID();
        $output->writeln('User: ' . $uid);

        // [1]/[2] Bearer + accountId
        try {
            $bearer = $this->userContext->resolveBearer($uid);
            $output->writeln('[1] Bearer: OK (' . strlen($bearer) . ' chars)');
        } catch (\Throwable $e) {
            $output->writeln('<error>[1] Bearer fehlgeschlagen: ' . $e->getMessage() . '</error>');
            $output->writeln('→OIDC/H2CK-Problem — diag:jmap für die Kettendiagnose ausführen.');
            return Command::FAILURE;
        }
        try {
            $accountId = $this->userContext->resolveAccountId($uid);
            $output->writeln('[2] accountId: ' . $accountId);
        } catch (\Throwable $e) {
            $output->writeln('<error>[2] accountId fehlgeschlagen: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // [3] Script-Liste
        try {
            $scripts = $this->sieve->listScriptsWithBodies($uid)['scripts'];
        } catch (\Throwable $e) {
            $output->writeln('<error>[3] SieveScript/get fehlgeschlagen: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('[3] Scripts (' . count($scripts) . '):');
        $activeName = null;
        foreach ($scripts as $s) {
            $output->writeln(sprintf(
                '    - %s [%s] isActive=%s blobId=%s body=%d chars',
                $s['name'],
                $s['isMain'] ? 'MAIN' : 'filter',
                $s['isActive'] ? 'ACTIVE' : 'inactive',
                substr((string) ($s['blobId'] ?? ''), 0, 16),
                strlen((string) ($s['body'] ?? '')),
            ));
            if (!empty($s['isActive'])) { $activeName = $s['name']; }
        }
        if ($activeName === null) {
            $output->writeln('<comment>    ⚠ KEIN aktives Script — Stalwart führt KEINE Filter aus!</comment>');
        }

        // [4] Aktiver Script: Kopf
        foreach ($scripts as $s) {
            if (!empty($s['isActive'])) {
                $output->writeln('[4] Aktiver Script „' . $s['name'] . '“ — erste 15 Zeilen:');
                foreach (explode("\n", (string) $s['body']) as $i => $line) {
                    if ($i >= 15) { $output->writeln('    …'); break; }
                    $output->writeln('    ' . $line);
                }
            }
        }

        // [5] Capabilities-Verifikation: Blob-Upload + SieveScript/validate
        $probe = "require [\"imap4flags\",\"fileinto\"];"
            . "\nif header :contains \"subject\" \"x-probe-never-matches\" { addflag \"\$label1\"; fileinto \"INBOX\"; }";
        try {
            $bearer = $this->userContext->resolveBearer($uid);
            $accountId = $this->userContext->resolveAccountId($uid);
            $blobId = $this->sieve->uploadBlob($accountId, $bearer, $probe);
            $resp = $this->stalwart->jmapCall($bearer, [
                ['SieveScript/validate', [
                    'accountId' => $accountId,
                    'blobId' => $blobId,
                ], 'v0'],
            ], ['urn:ietf:params:jmap:sieve']);
            $err = $resp['methodResponses'][0][1]['error'] ?? null;
            $output->writeln('[5] Validate imap4flags+fileinto: ' . ($err !== null
                ? 'FEHLER ' . json_encode($err, JSON_UNESCAPED_SLASHES)
                : 'OK (Capabilities akzeptiert)'));
        } catch (\Throwable $e) {
            $output->writeln('<comment>[5] Validate fehlgeschlagen: ' . $e->getMessage() . '</comment>');
        }

        // [6] Disabled-Pref
        $disabled = [];
        try {
            $disabled = $this->sieve->getDisabledFilters($uid);
        } catch (\Throwable $e) { /* ignore */ }
        $output->writeln('[6] Disabled-Filters-Pref: ' . json_encode($disabled));

        $output->writeln('Fertig — bei „aktiv“ aber wirkungslosen Filtern: Stalwart-Log auf sieve-Evaluation prüfen.');
        return Command::SUCCESS;
    }
}
