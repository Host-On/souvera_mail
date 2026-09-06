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
        'urn:ietf:params:jmap:sieve',
    ];

    private int $callCounter = 0;

    public function __construct(
        private IUserSession $userSession,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwartAdmin,
        private \OCP\Http\Client\IClientService $clientService,
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
     * Lädt die ORIGINAL-RFC822-Rohbytes einer Email (exakt wie vom Server
     * empfangen, inkl. aller Header) — Voraussetzung für das PMG-Bayes-
     * Training, das die unveränderte Original-Nachricht braucht.
     *
     * Weg: Email/get → blobId, dann JMAP-Download-Endpoint aus der Session
     * (RFC 8620 §2 `downloadUrl`-Template) mit dem User-Bearer-Token.
     *
     * @return array{bytes: string, messageId: ?string, subject: ?string}|null
     */
    public function fetchRawMailBytes(string $accountId, string $emailId): ?array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $result = $this->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => [$emailId],
            'properties' => ['blobId', 'messageId', 'subject', 'receivedAt'],
        ]);
        if (isset($result['error'])) {
            $this->logger->error('V2JmapProxy: Email/get failed: ' . $result['error']);
            return null;
        }

        $callId = 'c' . ($this->callCounter - 1);
        $resp = $result['responses'][$callId] ?? null;
        $list = $resp['args']['list'] ?? [];
        $email = $list[0] ?? null;
        if ($email === null || empty($email['blobId'])) {
            $this->logger->error('V2JmapProxy: Email/get returned no blob for ' . $emailId);
            return null;
        }

        try {
            $bearer = $this->userContext->resolveBearer($user->getUID());
            $session = $this->stalwartAdmin->fetchSessionAsUser($bearer);
        } catch (\Throwable $e) {
            $this->logger->error('V2JmapProxy: session fetch failed: ' . $e->getMessage());
            return null;
        }

        $downloadTemplate = (string) ($session['body']['downloadUrl'] ?? '');
        if ($downloadTemplate === '') {
            $downloadTemplate = '{server}/jmap/download/{accountId}/{blobId}/{name}';
        }

        $url = str_replace(
            ['{server}', '{accountId}', '{blobId}', '{name}', '{type}'],
            [$this->stalwartAdmin->getApiUrl() ?? '', $accountId, (string) $email['blobId'], 'mail.eml', 'message/rfc822'],
            $downloadTemplate
        );

        try {
            $client = $this->clientService->newClient();
            $response = $client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearer,
                    'Accept' => 'message/rfc822',
                ],
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('V2JmapProxy: blob download failed: ' . $e->getMessage());
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('V2JmapProxy: blob download HTTP ' . $response->getStatusCode());
            return null;
        }

        $bytes = (string) $response->getBody();
        if ($bytes === '') {
            return null;
        }

        $messageIds = $email['messageId'] ?? [];
        $messageId = is_array($messageIds) && $messageIds !== [] ? (string) $messageIds[0] : null;

        return [
            'bytes' => $bytes,
            'messageId' => $messageId,
            'subject' => (string) ($email['subject'] ?? ''),
        ];
    }

    /**
     * Lädt rohe Bytes als JMAP-Blob hoch — über die path-style Upload-URL
     * (RFC 8620 §6.1, `POST {api}/jmap/upload/{accountId}/`), dieselbe
     * Strecke, die der Android-Client (uploadUrl) und die Sieve-Verwaltung
     * erfolgreich nutzen. Der frühere `Blob/upload`-Methodenaufruf wurde von
     * Stalwart nicht akzeptiert, wodurch Anhänge im Web-Composer verloren
     * gingen.
     *
     * @return array{blobId: string, size: int, type: string}|null
     */
    public function uploadBlob(string $accountId, string $bytes, string $contentType): ?array
    {
        $apiUrl = $this->stalwartAdmin->getApiUrl();
        if ($apiUrl === null) {
            $this->logger->warning('V2JmapProxy: blob upload failed — Stalwart API URL not configured');
            return null;
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $url = \rtrim($apiUrl, '/')
            . '/jmap/upload/'
            . \rawurlencode($accountId)
            . '/';

        try {
            $bearer = $this->userContext->resolveBearer($user->getUID());
            $client = $this->clientService->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearer,
                    'Content-Type' => $contentType,
                    'Accept' => 'application/json',
                ],
                'body' => $bytes,
                'timeout' => 120,
                'connect_timeout' => 30,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('V2JmapProxy: blob upload failed: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning(
                'V2JmapProxy: blob upload HTTP ' . $response->getStatusCode() . ': '
                . \substr((string) $response->getBody(), 0, 300)
            );
            return null;
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        if (!\is_array($decoded) || !isset($decoded['blobId'])) {
            $this->logger->warning('V2JmapProxy: blob upload returned unexpected payload');
            return null;
        }

        return [
            'blobId' => (string) $decoded['blobId'],
            'size' => (int) ($decoded['size'] ?? \strlen($bytes)),
            'type' => (string) ($decoded['type'] ?? $contentType),
        ];
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
