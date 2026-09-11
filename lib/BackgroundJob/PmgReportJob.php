<?php

declare(strict_types=1);

namespace OCA\SouveraMail\BackgroundJob;

use OCA\SouveraMail\Service\PmgReportService;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued vom JMAP-Proxy-Hook nach Junk-Moves: meldet eine Mail als spam/ham
 * an das PMG-Bayes-Learning, ohne den Move-Request zu blockieren.
 *
 * Cron-Latenz ist akzeptabel — das Learning ist nicht nutzersichtbar.
 * PMG-Duplikate sind harmlos (bayes_seen dedupliziert serverseitig).
 */
class PmgReportJob extends QueuedJob
{
    protected function run($argument): void
    {
        $payload = \json_decode((string) $argument, true);
        if (!\is_array($payload) || !isset($payload['userId'], $payload['accountId'], $payload['emailId'], $payload['class'])) {
            return;
        }

        // Job-Konstruktor-DI ist für QueuedJobs nicht verlässlich — Services
        // hier explizit aus dem Container auflösen.
        try {
            // fetchRawMailBytes liest die UID aus der User-Session — im Cron-
            // Kontext ist die leer, daher den Nutzer für den Job nachbilden.
            $user = \OCP\Server::get(\OCP\IUserManager::class)->get((string) $payload['userId']);
            if ($user === null) {
                \OCP\Server::get(LoggerInterface::class)->debug(
                    'Souvera Mail: PMG report job skipped — unknown user ' . $payload['userId'],
                    ['app' => 'souvera_mail']
                );
                return;
            }
            \OCP\Server::get(\OCP\IUserSession::class)->setUser($user);

            /** @var PmgReportService $svc */
            $svc = \OCP\Server::get(PmgReportService::class);
            $result = $svc->report(
                (string) $payload['userId'],
                (string) $payload['accountId'],
                (string) $payload['class'],
                (string) $payload['emailId']
            );

            $status = ($result['success'] ?? false) ? 'success' : (($result['partial'] ?? false) ? 'partial' : 'error');
            $logger = \OCP\Server::get(LoggerInterface::class);
            $logger->debug('Souvera Mail: PMG report job ' . $payload['class'] . ' ' . $payload['emailId'] . ' → ' . $status, [
                'app' => 'souvera_mail',
            ]);
        } catch (\Throwable $e) {
            $logger = \OCP\Server::get(LoggerInterface::class);
            $logger->debug('Souvera Mail: PMG report job failed: ' . $e->getMessage(), [
                'app' => 'souvera_mail',
            ]);
        }
    }
}
