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
        if (!\interface_exists(IAvailabilityCoordinator::class)) {
            return false;
        }
        try {
            $coordinator = \OCP\Server::get(IAvailabilityCoordinator::class);
            return $coordinator->isEnabled();
        } catch (\Throwable $e) {
            return false;
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
                if ((string) $slot->getAvailability() !== 'ABSENT') {
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
        if (!$this->isSupported()) {
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
     * Combined state for the settings UI: NC data + current Sieve state.
     *
     * @return array<string, mixed>
     */
    public function getState(string $uid): array
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

        $data = $this->getCurrentOutOfOffice($uid);
        if ($data !== null) {
            $state['ncActive'] = true;
            try {
                $state['inEffect'] = \OCP\Server::get(IAvailabilityCoordinator::class)->isInEffect($data);
            } catch (\Throwable $e) {
                $state['inEffect'] = true;
            }
            $state['start'] = \date('Y-m-d', $data->getStartDate());
            $state['end'] = \date('Y-m-d', $data->getEndDate());
            $state['short'] = $data->getShortMessage();
            $state['long'] = $data->getMessage();
            $replacement = $data->getReplacementUserDisplayName();
            $state['replacement'] = $replacement ?? '';
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

        return $state;
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

        $data = $this->getCurrentOutOfOffice($uid);

        // Fallback: Klassische "persönliche Verfügbarkeit" (Abwesend-Zeiträume)
        // — viele Nutzer pflegen genau DORT ihre Abwesenheit statt im neuen
        // Out-of-Office-Dialog. Liegt ein aktiver Abwesend-Zeitraum vor,
        // wird er als Auto-Antwort übernommen.
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

        $inEffect = true;
        try {
            $inEffect = \OCP\Server::get(IAvailabilityCoordinator::class)->isInEffect($data);
        } catch (\Throwable $e) {
            // Treat as active — the date window is authoritative anyway.
        }

        if (!$inEffect) {
            try {
                $this->vacationService->set($uid, false, '', '');
                return ['ok' => true, 'changed' => true, 'active' => false];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $short = \trim($data->getShortMessage());
        $long = \trim($data->getMessage());
        $replacement = \trim((string) $data->getReplacementUserDisplayName());

        $subject = $short !== '' ? 'Abwesenheit: ' . $short : 'Abwesenheitsnotiz';
        $message = $long;
        if ($replacement !== '') {
            $message .= "\n\n" . 'Vertretung: ' . $replacement;
        }

        $hash = \sha1(\json_encode([
            $subject, $message,
            $data->getStartDate(), $data->getEndDate(),
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
                \date('Y-m-d', $data->getStartDate()),
                \date('Y-m-d', $data->getEndDate()),
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
