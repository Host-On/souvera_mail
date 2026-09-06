<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\PmgReport;
use OCA\SouveraMail\Db\PmgReportMapper;
use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates PMG spam/ham reports:
 *
 *   1. Fetch the ORIGINAL RFC 822 mail (JMAP blob) — PMG needs the exact
 *      bytes as received, not a client re-serialization.
 *   2. Learn/forget at the PMG learning API.
 *   3. Track the user's own reports, so leaving the junk folder can be
 *      classified correctly:
 *        - mail was user-reported       → forget/spam (revert)
 *        - mail was system-sorted       → learn/ham (false positive)
 *
 * Upsert semantics: the user's LATEST action per message wins.
 */
class PmgReportService
{
    public function __construct(
        private readonly PmgLearningService $learning,
        private readonly PmgReportMapper $reportMapper,
        private readonly V2JmapProxy $jmap,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Report a mail as spam or ham.
     *
     * @return array{operation: string, class: string, success: bool, partial: bool, nodes_ok: string, error?: string}
     */
    public function report(string $userId, string $accountId, string $class, string $emailId): array
    {
        $class = strtolower($class);
        if (!in_array($class, ['spam', 'ham'], true)) {
            return ['operation' => 'learn', 'class' => $class, 'success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'Invalid class'];
        }

        $raw = $this->jmap->fetchRawMailBytes($accountId, $emailId);
        if ($raw === null) {
            return ['operation' => 'learn', 'class' => $class, 'success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'Original mail could not be fetched (JMAP blob download failed)'];
        }

        $messageIdHash = $this->hashFor($accountId, (string) ($raw['messageId'] ?? $emailId));

        $pmg = $this->learning->learn($class, 'learn', $raw['bytes']);

        $report = new PmgReport();
        $report->setUserId($userId);
        $report->setAccountId($accountId);
        $report->setEmailId($emailId);
        $report->setMessageId((string) ($raw['messageId'] ?? ''));
        $report->setMessageIdHash($messageIdHash);
        $report->setClass($class);
        $report->setReportedAt(date('Y-m-d H:i:s'));
        $this->reportMapper->upsert($report);

        $this->logger->info('PMG report: ' . $class . ' by ' . $userId, [
            'app' => 'souvera_mail',
            'nodes_ok' => $pmg['nodes_ok'] ?? '',
            'partial' => $pmg['partial'] ?? false,
        ]);

        return [
            'operation' => 'learn',
            'class' => $class,
            'success' => (bool) ($pmg['success'] ?? false),
            'partial' => (bool) ($pmg['partial'] ?? false),
            'nodes_ok' => (string) ($pmg['nodes_ok'] ?? ''),
        ] + (isset($pmg['error']) ? ['error' => $pmg['error']] : []);
    }

    /**
     * Mail leaves the junk folder: revert the user's own spam report, or
     * (if the system had sorted it) report it as ham (false positive).
     *
     * @return array{operation: string, class: string, success: bool, partial: bool, nodes_ok: string, reverted: bool, error?: string}
     */
    public function reportRestoredFromJunk(string $userId, string $accountId, string $emailId): array
    {
        $raw = $this->jmap->fetchRawMailBytes($accountId, $emailId);
        if ($raw === null) {
            return ['operation' => 'learn', 'class' => 'ham', 'success' => false, 'partial' => false, 'nodes_ok' => '', 'reverted' => false, 'error' => 'Original mail could not be fetched (JMAP blob download failed)'];
        }

        $messageIdHash = $this->hashFor($accountId, (string) ($raw['messageId'] ?? $emailId));
        $own = $this->reportMapper->findLatestByHash($userId, $messageIdHash);

        // User reported this mail themselves → take the report back.
        if ($own !== null && $own->getClass() === 'spam') {
            $pmg = $this->learning->learn('spam', 'forget', $raw['bytes']);
            $this->reportMapper->deleteByUserAndHash($userId, $messageIdHash);

            return [
                'operation' => 'forget',
                'class' => 'spam',
                'success' => (bool) ($pmg['success'] ?? false),
                'partial' => (bool) ($pmg['partial'] ?? false),
                'nodes_ok' => (string) ($pmg['nodes_ok'] ?? ''),
                'reverted' => true,
            ] + (isset($pmg['error']) ? ['error' => $pmg['error']] : []);
        }

        // The system (filter) had sorted it → train it as ham (false positive).
        $pmg = $this->learning->learn('ham', 'learn', $raw['bytes']);

        $report = new PmgReport();
        $report->setUserId($userId);
        $report->setAccountId($accountId);
        $report->setEmailId($emailId);
        $report->setMessageId((string) ($raw['messageId'] ?? ''));
        $report->setMessageIdHash($messageIdHash);
        $report->setClass('ham');
        $report->setReportedAt(date('Y-m-d H:i:s'));
        $this->reportMapper->upsert($report);

        return [
            'operation' => 'learn',
            'class' => 'ham',
            'success' => (bool) ($pmg['success'] ?? false),
            'partial' => (bool) ($pmg['partial'] ?? false),
            'nodes_ok' => (string) ($pmg['nodes_ok'] ?? ''),
            'reverted' => false,
        ] + (isset($pmg['error']) ? ['error' => $pmg['error']] : []);
    }

    /**
     * Explicit forget: revert the last report for this mail (any class).
     *
     * @return array{operation: string, class: string, success: bool, partial: bool, nodes_ok: string, error?: string}
     */
    public function forgetLast(string $userId, string $accountId, string $emailId): array
    {
        $raw = $this->jmap->fetchRawMailBytes($accountId, $emailId);
        if ($raw === null) {
            return ['operation' => 'forget', 'class' => '', 'success' => false, 'partial' => false, 'nodes_ok' => '', 'error' => 'Original mail could not be fetched'];
        }

        $messageIdHash = $this->hashFor($accountId, (string) ($raw['messageId'] ?? $emailId));
        $own = $this->reportMapper->findLatestByHash($userId, $messageIdHash);
        $class = $own !== null ? $own->getClass() : 'spam';

        $pmg = $this->learning->learn($class, 'forget', $raw['bytes']);
        $this->reportMapper->deleteByUserAndHash($userId, $messageIdHash);

        return [
            'operation' => 'forget',
            'class' => $class,
            'success' => (bool) ($pmg['success'] ?? false),
            'partial' => (bool) ($pmg['partial'] ?? false),
            'nodes_ok' => (string) ($pmg['nodes_ok'] ?? ''),
        ] + (isset($pmg['error']) ? ['error' => $pmg['error']] : []);
    }

    /**
     * @return PmgReport[]
     */
    public function reportsForUser(string $userId, int $limit = 50): array
    {
        return $this->reportMapper->findByUser($userId, $limit);
    }

    private function hashFor(string $accountId, string $messageId): string
    {
        return hash('sha256', $accountId . '|' . $messageId);
    }
}
