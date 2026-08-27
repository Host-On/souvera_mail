<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\IUser;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\User\IAvailabilityCoordinator;
use OCP\User\IOutOfOfficeData;
use Psr\Log\LoggerInterface;

/**
 * Bridges Nextcloud's built-in out-of-office/availability feature
 * (/settings/user/availability) to the mail auto-responder.
 *
 * Reading is done through the public OCP API
 * (IAvailabilityCoordinator + IOutOfOfficeData, since NC 28) — no
 * app-internal classes, no direct DB access. The data is mapped onto the
 * managed Sieve vacation block of VacationService:
 *
 *   shortMessage            → auto-reply subject ("Abwesenheit: …")
 *   message                 → auto-reply body
 *   replacementUser*        → extra "Vertretung" line in the body
 *   startDate / endDate     → Sieve date window (from / to)
 *
 * Writing back to Nextcloud happens from the FRONTEND through the official
 * OCS API of the dav app (POST/DELETE /ocs/v2.php/apps/dav/api/v1/outOfOffice/{uid}),
 * followed by a call to syncNow() so the Sieve responder reflects the change
 * immediately.
 */
class VacationSyncService
{
    private const PREF_SYNC = 'pref_vacation_sync';
    private const PREF_HASH = 'pref_vacation_hash';

    public function __construct(
        private VacationService $vacationService,
        private IUserManager $userManager,
        private IConfig $config,
        private \OCP\IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function isSyncEnabled(string $uid): bool
    {
        return $this->config->getUserValue($uid, 'souvera_mail', self::PREF_SYNC, '1') === '1';
    }

    public function setSyncEnabled(string $uid, bool $enabled): void
    {
        $this->config->setUserValue($uid, 'souvera_mail', self::PREF_SYNC, $enabled ? '1' : '0');
    }

    /**
     * True when the NC feature is present (NC 28+) and enabled on the instance.
     */
    public function isSupported(): bool
    {
        if (\interface_exists(\OCP\User\IAbsenceManager::class)) {
            return true;
        }
        if (\interface_exists(IAvailabilityCoordinator::class)) {
            try {
                return \OCP\Server::get(IAvailabilityCoordinator::class)->isEnabled();
            } catch (\Throwable $e) {
                // Fall through to DB check.
            }
        }
        try {
            return $this->db->tableExists('dav_absence');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Liefert den AKTUELLEN oder — falls keiner läuft — den NÄCHSTEN
     * geplanten Abwesenheitszeitraum. Der Nutzer stellt im NC-Dialog
     * ausdrücklich den "nächsten Abwesenheitszeitraum" ein — der darf nicht
     * als "keine Abwesenheit" gewertet werden.
     *
     * @return array{firstDay: int, lastDay: int, status: string, message: string, replacement: string, planned: bool}|null
     */
    public function getRelevantAbsence(string $uid): ?array
    {
        $current = $this->getCurrentAbsence($uid);
        if ($current !== null) {
            return $current + ['planned' => false];
        }

        try {
            $rows = $this->db->executeQuery(
                'SELECT * FROM `*PREFIX*dav_absence` WHERE `user_id` = ?',
                [$uid]
            )->fetchAll();
            $now = \time();
            $future = null;
            foreach ($rows as $row) {
                $first = (int) ($row['first_day'] ?? 0);
                $last = (int) ($row['last_day'] ?? 0);
                if ($first === 0 || $last === 0) {
                    continue;
                }
                if ($first <= $now && $now <= $last) {
                    return [
                        'firstDay' => $first,
                        'lastDay' => $last,
                        'status' => (string) ($row['status'] ?? ''),
                        'message' => (string) ($row['message'] ?? ''),
                        'replacement' => (string) ($row['replacement_user_display_name'] ?? ''),
                        'planned' => false,
                    ];
                }
                if ($first > $now && ($future === null || $first < $future['firstDay'])) {
                    $future = [
                        'firstDay' => $first,
                        'lastDay' => $last,
                        'status' => (string) ($row['status'] ?? ''),
                        'message' => (string) ($row['message'] ?? ''),
                        'replacement' => (string) ($row['replacement_user_display_name'] ?? ''),
                        'planned' => true,
                    ];
                }
            }
            return $future;
        } catch (\Throwable $e) {
            $this->logger->debug('Absence via DB (future) failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Liefert den aktuell laufenden "Abwesend"-Zeitraum der klassischen
     * persönlichen Verfügbarkeit, oder null wenn der Nutzer nicht abwesend ist.
     *
     * @return array{end: int, message: string}|null
     */
    public function currentAbsentSlot(string $uid): ?array
    {
        if (!\interface_exists(IAvailabilityCoordinator::class)) {
            return null;
        }
        try {
            $coordinator = \OCP\Server::get(IAvailabilityCoordinator::class);
            if (!\method_exists($coordinator, 'getAvailability')) {
                return null;
            }
            $user = $this->userManager->get($uid);
            if ($user === null) {
                return null;
            }
            $now = \time();
            $slots = $coordinator->getAvailability($user);
            \usort($slots, static fn ($a, $b) => $a->getBeginTime() <=> $b->getBeginTime());
            foreach ($slots as $slot) {
                if (!\method_exists($slot, 'getAvailability') || !\method_exists($slot, 'getBeginTime')) {
                    continue;
                }
                if (\strtolower((string) $slot->getAvailability()) !== 'absent') {
                    continue;
                }
                $begin = (int) $slot->getBeginTime();
                $end = \method_exists($slot, 'getEndTime') ? (int) $slot->getEndTime() : $begin;
                if ($begin <= $now && $now <= $end) {
                    $message = \method_exists($slot, 'getMessage') ? (string) $slot->getMessage() : '';
                    return ['end' => $end, 'message' => \trim($message)];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('VacationSyncService: availability fallback failed: ' . $e->getMessage());
        }
        return null;
    }

    /** @return IOutOfOfficeData|null */
    public function getCurrentOutOfOffice(string $uid): ?IOutOfOfficeData
    {
        if (!\interface_exists(IAvailabilityCoordinator::class)) {
            return null;
        }
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return null;
        }
        try {
            return \OCP\Server::get(IAvailabilityCoordinator::class)->getCurrentOutOfOfficeData($user);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Einheitliche Abwesenheits-Abfrage über ALLE Nextcloud-Versionen:
     *   NC 31+ : IAbsenceManager::getCurrentAbsenceData (Out-of-Office)
     *   NC 28-30: IAvailabilityCoordinator::getCurrentOutOfOfficeData
     *   Fallback: direkter DB-Zugriff auf oc_dav_absence (versionsunabhängig)
     *
     * @return array{firstDay: int, lastDay: int, status: string, message: string, replacement: string}|null
     */
    public function getCurrentAbsence(string $uid): ?array
    {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return null;
        }

        // NC 31+: neues Absence-Management (das "Out-of-Office" der neueren
        // Versionen — auf NC 33+ existiert IAvailabilityCoordinator nicht mehr).
        if (\interface_exists(\OCP\User\IAbsenceManager::class)) {
            try {
                $manager = \OCP\Server::get(\OCP\User\IAbsenceManager::class);
                $data = $manager->getCurrentAbsenceData($user);
                if ($data !== null && \method_exists($data, 'getFirstDay')) {
                    return [
                        'firstDay' => (int) $data->getFirstDay(),
                        'lastDay' => (int) $data->getLastDay(),
                        'status' => \method_exists($data, 'getStatus') ? (string) $data->getStatus() : '',
                        'message' => \method_exists($data, 'getMessage') ? (string) $data->getMessage() : '',
                        'replacement' => \method_exists($data, 'getReplacementUserDisplayName')
                            ? (string) $data->getReplacementUserDisplayName()
                            : '',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Absence via IAbsenceManager failed: ' . $e->getMessage());
            }
        }

        // NC 28-30: Out-of-Office über den Availability-Koordinator.
        if (\interface_exists(IAvailabilityCoordinator::class)) {
            try {
                $data = \OCP\Server::get(IAvailabilityCoordinator::class)->getCurrentOutOfOfficeData($user);
                if ($data !== null) {
                    return [
                        'firstDay' => $data->getStartDate(),
                        'lastDay' => $data->getEndDate(),
                        'status' => $data->getShortMessage(),
                        'message' => $data->getMessage(),
                        'replacement' => $data->getReplacementUserDisplayName() ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Absence via IAvailabilityCoordinator failed: ' . $e->getMessage());
            }
        }

        // Letzter Fallback: direkte DB-Abfrage (funktioniert auf jeder Version).
        try {
            $rows = $this->db->executeQuery(
                'SELECT * FROM `*PREFIX*dav_absence` WHERE `user_id` = ?',
                [$uid]
            )->fetchAll();
            $now = \time();
            foreach ($rows as $row) {
                $first = (int) ($row['first_day'] ?? 0);
                $last = (int) ($row['last_day'] ?? 0);
                if ($first !== 0 && $first <= $now && $now <= $last) {
                    return [
                        'firstDay' => $first,
                        'lastDay' => $last,
                        'status' => (string) ($row['status'] ?? ''),
                        'message' => (string) ($row['message'] ?? ''),
                        'replacement' => (string) ($row['replacement_user_display_name'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Absence via DB failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Combined state for the settings UI: NC data + current Sieve state.
     *
     * @return array<string, mixed>
     */
    public function getState(string $uid): array
    {
        try {
            return $this->getStateInternal($uid);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: vacation getState failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return [
                'supported' => true,
                'syncEnabled' => $this->isSyncEnabled($uid),
                'ncActive' => false,
                'inEffect' => false,
                'start' => '',
                'end' => '',
                'short' => '',
                'long' => '',
                'replacement' => '',
                'vacation' => ['enabled' => false, 'subject' => '', 'message' => '', 'from' => '', 'to' => ''],
                'debug' => ['stateError' => $e->getMessage()],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function getStateInternal(string $uid): array
    {
        $state = [
            'supported' => $this->isSupported(),
            'syncEnabled' => $this->isSyncEnabled($uid),
            'ncActive' => false,
            'inEffect' => false,
            'start' => '',
            'end' => '',
            'short' => '',
            'long' => '',
            'replacement' => '',
        ];

        $data = $this->getRelevantAbsence($uid);
        if ($data !== null) {
            $state['ncActive'] = true;
            $state['inEffect'] = !$data['planned'];
            $state['start'] = \date('Y-m-d', $data['firstDay']);
            $state['end'] = \date('Y-m-d', $data['lastDay']);
            $state['short'] = $data['status'];
            $state['long'] = $data['message'];
            $state['replacement'] = $data['replacement'];
        }

        if (!$state['ncActive']) {
            // Fallback: klassische "persönliche Verfügbarkeit" (Abwesend-Slots).
            $slot = $this->currentAbsentSlot($uid);
            if ($slot !== null) {
                $state['ncActive'] = true;
                $state['inEffect'] = true;
                $state['start'] = '';
                $state['end'] = \date('Y-m-d', $slot['end']);
                $state['short'] = 'Abwesenheitsnotiz';
                $state['long'] = $slot['message'] !== ''
                    ? $slot['message']
                    : 'Ich bin derzeit abwesend und werde Ihre Nachricht nach meiner Rückkehr beantworten.';
                $state['replacement'] = '';
            }
        }

        try {
            $state['vacation'] = $this->vacationService->get($uid);
        } catch (\Throwable $e) {
            $state['vacation'] = ['enabled' => false, 'subject' => '', 'message' => '', 'from' => '', 'to' => ''];
        }

        $state['debug'] = $this->collectDebug($uid);

        return $state;
    }

    /**
     * Diagnosedaten für die Einstellungs-UI — zeigt exakt, was der Server
     * an Abwesenheitsdaten sieht (NC-Version, APIs, Tabellen, Rohdaten).
     *
     * @return array<string, mixed>
     */
    private function collectDebug(string $uid): array
    {
        $debug = [
            'ncVersion' => \implode('.', \OCP\Util::getVersion()),
            'absenceManager' => \interface_exists(\OCP\User\IAbsenceManager::class),
            'availabilityCoordinator' => \interface_exists(IAvailabilityCoordinator::class),
        ];

        $current = $this->getCurrentAbsence($uid);
        $debug['currentAbsence'] = $current !== null
            ? ['firstDay' => $current['firstDay'], 'lastDay' => $current['lastDay'], 'status' => $current['status']]
            : null;

        try {
            $debug['tableAbsence'] = $this->db->tableExists('dav_absence');
        } catch (\Throwable $e) {
            $debug['tableAbsence'] = 'error: ' . $e->getMessage();
        }
        try {
            $debug['tableAvailability'] = $this->db->tableExists('dav_availability');
        } catch (\Throwable $e) {
            $debug['tableAvailability'] = 'error: ' . $e->getMessage();
        }

        try {
            $rows = $this->db->executeQuery(
                'SELECT * FROM `*PREFIX*dav_absence` WHERE `user_id` = ?',
                [$uid]
            )->fetchAll();
            $debug['absenceRows'] = \count($rows);
            $debug['absenceData'] = [];
            foreach ($rows as $row) {
                $debug['absenceData'][] = [
                    'firstDay' => $row['first_day'] ?? null,
                    'lastDay' => $row['last_day'] ?? null,
                    'status' => $row['status'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $debug['absenceRows'] = 'error: ' . $e->getMessage();
        }

        try {
            $rows = $this->db->executeQuery(
                'SELECT `availability_level`, `start_time`, `end_time` FROM `*PREFIX*dav_availability` WHERE `user_id` = ?',
                [$uid]
            )->fetchAll();
            $debug['availabilityRows'] = \count($rows);
            $debug['availabilityData'] = $rows;
        } catch (\Throwable $e) {
            $debug['availabilityRows'] = 'error: ' . $e->getMessage();
        }

        return $debug;
    }

    /**
     * Pull the current NC out-of-office data into the Sieve responder.
     * No-ops when the NC feature is off, sync is disabled, or the data has
     * not changed since the last sync (hash comparison).
     *
     * @return array{ok: bool, changed: bool, active: bool, error?: string}
     */
    public function syncNow(string $uid): array
    {
        if (!$this->isSupported()) {
            return ['ok' => true, 'changed' => false, 'active' => false];
        }
        if (!$this->isSyncEnabled($uid)) {
            // Sync deaktiviert — Responder abschalten.
            try {
                $this->vacationService->set($uid, false, '', '');
                return ['ok' => true, 'changed' => true, 'active' => false];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // AKTUELLER oder NÄCHSTER geplanter Zeitraum — das Sieve-Datumsfenster
        // (from/to) entscheidet, wann die Antwort wirklich verschickt wird.
        $data = $this->getRelevantAbsence($uid);

        // Fallback: Klassische "persönliche Verfügbarkeit" (Abwesend-Zeiträume).
        $absentSlot = $data === null ? $this->currentAbsentSlot($uid) : null;

        if ($data === null && $absentSlot === null) {
            // Weder NC-Abwesenheit noch Abwesend-Zeitraum → Responder aus.
            try {
                $this->vacationService->set($uid, false, '', '');
                $this->config->setUserValue($uid, 'souvera_mail', self::PREF_HASH, 'none');
                return ['ok' => true, 'changed' => true, 'active' => false];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        if ($data === null && $absentSlot !== null) {
            $subject = 'Abwesenheitsnotiz';
            $message = $absentSlot['message'] !== ''
                ? $absentSlot['message']
                : 'Ich bin derzeit abwesend und werde Ihre Nachricht nach meiner Rückkehr beantworten.';
            $from = '';
            $to = \date('Y-m-d', $absentSlot['end']);
            $hash = \sha1(\json_encode(['availability', $subject, $message, $from, $to], JSON_UNESCAPED_SLASHES));
            if ($this->config->getUserValue($uid, 'souvera_mail', self::PREF_HASH, '') === $hash) {
                return ['ok' => true, 'changed' => false, 'active' => true];
            }
            try {
                $this->vacationService->set($uid, true, $subject, $message, $from, $to);
                $this->config->setUserValue($uid, 'souvera_mail', self::PREF_HASH, $hash);
                return ['ok' => true, 'changed' => true, 'active' => true];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $short = \trim($data['status']);
        $long = \trim($data['message']);
        $replacement = \trim($data['replacement']);

        $subject = $short !== '' ? 'Abwesenheit: ' . $short : 'Abwesenheitsnotiz';
        $message = $long;
        if ($replacement !== '') {
            $message .= "\n\n" . 'Vertretung: ' . $replacement;
        }

        $hash = \sha1(\json_encode([
            $subject, $message,
            $data['firstDay'], $data['lastDay'],
        ], JSON_UNESCAPED_SLASHES));
        if ($this->config->getUserValue($uid, 'souvera_mail', self::PREF_HASH, '') === $hash) {
            return ['ok' => true, 'changed' => false, 'active' => true];
        }

        try {
            $this->vacationService->set(
                $uid,
                true,
                $subject,
                $message,
                \date('Y-m-d', $data['firstDay']),
                \date('Y-m-d', $data['lastDay']),
            );
            $this->config->setUserValue($uid, 'souvera_mail', self::PREF_HASH, $hash);
            return ['ok' => true, 'changed' => true, 'active' => true];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'VacationSyncService: sync failed for ' . $uid . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
