<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Thin JMAP proxy for the v2 Vue-3 frontend.
 *
 * Resolves the current Nextcloud user's Stalwart accountId and OIDC bearer
 * token, then executes one or more JMAP method calls against the Stalwart
 * REST/JMAP endpoint. All HTTP and session management is delegated to
 * {@see StalwartAdminService}.
 *
 * Every public method returns the result array directly — the caller must
 * handle error extraction (notCreated/notUpdated/notFound).
 */
class V2JmapProxy
{
    /** Standard JMAP capabilities needed by the v2 client. */
    private const CAPS = [
        'urn:ietf:params:jmap:mail',
        'urn:ietf:params:jmap:submission',
        'urn:ietf:params:jmap:blob',
    ];

    private int $callCounter = 0;

    public function __construct(
        private IUserSession $userSession,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwartAdmin,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the current user's Stalwart JMAP accountId, or null if not
     * logged in or resolution fails.
     */
    public function getCurrentAccountId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }
        try {
            return $this->userContext->resolveAccountId($user->getUID());
        } catch (\Throwable $e) {
            $this->logger->error('V2JmapProxy: cannot resolve accountId: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Executes one or more JMAP method calls as the current user and
     * returns the parsed methodResponses keyed by callId.
     *
     * @param list<array{string, array}> $methodCalls  [methodName, arguments]
     * @return array{responses: array<string, array>, sessionState: string|null}|array{error: string}
     */
    public function call(array $methodCalls): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ['error' => 'Not logged in'];
        }

        // Build triples with auto-generated callIds.
        $triples = [];
        $expectedIds = [];
        foreach ($methodCalls as $pair) {
            $callId = 'c' . $this->callCounter++;
            $triples[] = [$pair[0], $pair[1] ?? [], $callId];
            $expectedIds[] = $callId;
        }

        try {
            $bearer = $this->userContext->resolveBearer($user->getUID());
            $response = $this->stalwartAdmin->jmapCall($bearer, $triples, self::CAPS);

            if (!\is_array($response) || !isset($response['methodResponses'])) {
                return ['error' => 'Invalid JMAP response', 'raw' => $response];
            }

            $responses = $response['methodResponses'];
            $byCallId = [];
            foreach ($responses as $triple) {
                $name = $triple[0] ?? '?';
                $args = $triple[1] ?? [];
                $callId = $triple[2] ?? '';
                $byCallId[$callId] = ['name' => $name, 'args' => $args];
            }

            return ['responses' => $byCallId, 'sessionState' => $response['sessionState'] ?? null];
        } catch (\Throwable $e) {
            $this->logger->error('V2JmapProxy: call failed: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Convenience: single JMAP call → args array or error.
     * The callId is always "cN" where N is the auto-incremented counter.
     */
    public function singleCall(string $method, array $args): array
    {
        $numCalls = $this->callCounter;
        $result = $this->call([[$method, $args]]);
        if (isset($result['error'])) {
            return $result;
        }
        $callId = 'c' . $numCalls;
        $resp = $result['responses'][$callId] ?? null;
        if ($resp === null) {
            return ['error' => "No response for callId {$callId}"];
        }
        if ($resp['name'] === 'error' || isset($resp['args']['type'])) {
            return ['error' => ($resp['args']['description'] ?? $resp['args']['type'] ?? 'unknown')];
        }
        return ['data' => $resp['args']];
    }
}
