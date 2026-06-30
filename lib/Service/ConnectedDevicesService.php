<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Lists and revokes the current user's active Nextcloud sessions
 * (browsers + NC client apps such as Files/Talk/Calendar).
 *
 * Stalwart 0.16 does NOT expose a JMAP UserSession object — verified
 * against `crates/jmap/src/registry/get.rs` (no `Session`/`Login`/`AuthToken`
 * variant in the public ObjectType list). Persistent Stalwart mail-client
 * identity lives entirely in `AppPassword` entries which the user manages
 * via {@see AppPasswordService}. This service is therefore strictly
 * Nextcloud-scoped — it complements the App Passwords UI but does not
 * touch Stalwart.
 *
 * The "current" session (i.e. the one the user is logged into right now)
 * is best-effort detected via `ISession::getId()` → `ITokenProvider::
 * getToken()`. When that lookup fails (token rotated, public-share session,
 * etc.) we simply do not mark any row as current rather than guess.
 */
class ConnectedDevicesService
{
    public function __construct(
        private ITokenProvider $tokenProvider,
        private IUserManager $userManager,
        private ISession $session,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     type: string,
     *     lastActivity: int,
     *     scope: array<string, mixed>,
     *     current: bool
     * }>
     */
    public function listForUser(string $userId): array
    {
        $user = $this->requireUser($userId);
        $currentTokenId = $this->resolveCurrentTokenId();
        $items = [];
        foreach ($this->tokenProvider->getTokenByUser($user) as $tok) {
            try {
                $id = $tok->getId();
            } catch (\Throwable $e) {
                // Skip un-readable token entries — better to drop one row
                // than to fail the whole list.
                $this->logger->warning(
                    'Souvera Mail: skipping unreadable token: ' . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
                continue;
            }
            $items[] = [
                'id' => $id,
                'name' => $this->safeName($tok),
                'type' => $this->safeType($tok),
                'lastActivity' => $this->safeLastActivity($tok),
                'scope' => $this->safeScope($tok),
                'current' => $currentTokenId !== null && $id === $currentTokenId,
            ];
        }
        // Most-recently-active first; current session pinned to the top.
        \usort($items, function ($a, $b) {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }
            return $b['lastActivity'] <=> $a['lastActivity'];
        });
        return $items;
    }

    private function safeName(IToken $tok): string
    {
        try {
            $n = $tok->getName();
            return $n !== null && $n !== '' ? (string) $n : 'Unknown device';
        } catch (\Throwable $e) {
            return 'Unknown device';
        }
    }

    private function safeType(IToken $tok): string
    {
        try {
            return $tok->getType() === IToken::PERMANENT_TOKEN ? 'app' : 'browser';
        } catch (\Throwable $e) {
            return 'browser';
        }
    }

    private function safeLastActivity(IToken $tok): int
    {
        try {
            return (int) $tok->getLastActivity();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * NC token implementations differ on whether they expose
     * `getScopeAsArray()`: PublicKeyToken implements it; DefaultToken
     * implements it only since 27.x; some older / 3rd-party providers
     * skip it entirely or return null. Wrap so a missing/throwing
     * method never poisons the whole device list.
     */
    private function safeScope(IToken $tok): array
    {
        if (!\method_exists($tok, 'getScopeAsArray')) {
            return [];
        }
        try {
            $scope = $tok->getScopeAsArray();
            return \is_array($scope) ? $scope : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function revoke(string $userId, int $tokenId): void
    {
        $user = $this->requireUser($userId);
        $currentTokenId = $this->resolveCurrentTokenId();
        if ($currentTokenId !== null && $tokenId === $currentTokenId) {
            // The personal-settings UI hides this button for the current
            // session, but a hand-crafted DELETE would still log the user
            // out mid-request. Refuse — let them use the logout flow.
            throw new \InvalidArgumentException(
                'Refusing to revoke the session the request itself is using; use Nextcloud logout instead.'
            );
        }
        $this->tokenProvider->invalidateTokenById($user, $tokenId);
    }

    /**
     * Revokes every Nextcloud session belonging to the user EXCEPT the
     * current one. Returns the number of sessions successfully revoked.
     */
    public function revokeAllOthers(string $userId): int
    {
        $user = $this->requireUser($userId);
        $currentTokenId = $this->resolveCurrentTokenId();
        $revoked = 0;
        foreach ($this->tokenProvider->getTokenByUser($user) as $tok) {
            $id = $tok->getId();
            if ($currentTokenId !== null && $id === $currentTokenId) {
                continue;
            }
            try {
                $this->tokenProvider->invalidateTokenById($user, $id);
                $revoked++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Souvera Mail: skipping token ' . $id . ' during sign-out-others: ' . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
            }
        }
        return $revoked;
    }

    private function requireUser(string $userId): IUser
    {
        $user = $this->userManager->get($userId);
        if (!$user instanceof IUser) {
            throw new \RuntimeException("Nextcloud user '{$userId}' not found");
        }
        return $user;
    }

    /**
     * Best-effort detection: map the current PHP session-id to its NC token id.
     * Returns null when the lookup fails (token rotated, share session, etc.).
     */
    private function resolveCurrentTokenId(): ?int
    {
        try {
            $sessionId = $this->session->getId();
            if ($sessionId === '') {
                return null;
            }
            $token = $this->tokenProvider->getToken($sessionId);
            return $token->getId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
