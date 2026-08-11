<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Fetches emails from an external IMAP account and injects them into
 * the user's Stalwart mailbox via JMAP Email/import or Email/set.
 *
 * Uses PHP's IMAP extension. Runs as a background job per external account.
 */
class ExternalImapFetchService
{
    public function __construct(
        private ExternalAccountService $accountService,
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch new emails for a specific external account and inject into Stalwart.
     *
     * @return array{imported: int, error?: string}
     */
    public function fetchForUser(string $uid, string $accountId): array
    {
        $account = $this->accountService->getWithPassword($uid, $accountId);
        if ($account === null) {
            return ['imported' => 0, 'error' => 'Account not found'];
        }

        if (!\function_exists('imap_open')) {
            return ['imported' => 0, 'error' => 'PHP IMAP extension not available'];
        }

        $ssl = ($account['imap_ssl'] ?? 'ssl') === 'none' ? 'novalidate-cert' : 'ssl';
        $mailbox = '{' . $account['imap_host'] . ':' . $account['imap_port'] . '/imap/' . $ssl . '}INBOX';

        $conn = @\imap_open($mailbox, $account['username'], $account['password'], 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($conn === false) {
            $err = \imap_last_error() ?: 'Unknown IMAP error';
            $this->logger->warning('ExternalImapFetch: IMAP connect failed', [
                'email' => $account['email'], 'error' => $err,
            ]);
            return ['imported' => 0, 'error' => $err];
        }

        try {
            // Resolve Stalwart user context
            $bearer = $this->userContext->resolveBearer($uid);
            $stalwartAccountId = $this->userContext->resolveAccountId($uid);

            // Read last fetched UID from app config
            $lastUidKey = 'ext_fetch.' . $uid . '.' . $accountId . '.last_uid';
            $lastUid = (int) \OCP\Server::get(\OCP\IAppConfig::class)
                ->getValueString('souvera_mail', $lastUidKey, '0');

            // Get all UIDs in INBOX
            $uids = \imap_search($conn, 'ALL', \SE_UID);
            if ($uids === false || empty($uids)) {
                \imap_close($conn);
                return ['imported' => 0];
            }

            $newUids = \array_filter($uids, fn($uid) => $uid > $lastUid);
            if (empty($newUids)) {
                \imap_close($conn);
                return ['imported' => 0];
            }

            $imported = 0;
            $maxUid = $lastUid;

            foreach ($newUids as $uid) {
                try {
                    $header = \imap_fetchheader($conn, $uid, \FT_UID);
                    $body = \imap_body($conn, $uid, \FT_UID);

                    if ($header === false || $body === false) continue;

                    // Parse email for JMAP import
                    $rawMessage = $header . "\r\n" . $body;

                    // Upload as blob, then import via Email/import
                    $blobId = $this->uploadRawMessage($stalwartAccountId, $bearer, $account['email'], $rawMessage);

                    if ($blobId !== null) {
                        $this->stalwart->jmapCall($bearer, [
                            ['Email/import', [
                                'accountId' => $stalwartAccountId,
                                'emails' => [$account['email'] => [
                                    'blobId' => $blobId,
                                    'mailboxIds' => null, // Stalwart uses default (INBOX)
                                    'keywords' => ['$imported' => true],
                                ]],
                            ], 'e0'],
                        ], ['urn:ietf:params:jmap:mail']);

                        $imported++;
                    }

                    if ($uid > $maxUid) $maxUid = $uid;

                    // Limit per run to avoid timeouts
                    if ($imported >= 50) break;
                } catch (\Throwable $e) {
                    $this->logger->warning('ExternalImapFetch: import failed for UID ' . $uid, [
                        'email' => $account['email'], 'exception' => $e,
                    ]);
                }
            }

            // Store last fetched UID
            \OCP\Server::get(\OCP\IAppConfig::class)
                ->setValueString('souvera_mail', $lastUidKey, (string) $maxUid);

            $this->logger->info('ExternalImapFetch: imported ' . $imported . ' emails', [
                'email' => $account['email'], 'from' => $lastUid, 'to' => $maxUid,
            ]);

            return ['imported' => $imported];
        } finally {
            \imap_close($conn);
        }
    }

    /**
     * Upload a raw email message as a JMAP blob.
     */
    private function uploadRawMessage(string $accountId, string $bearer, string $email, string $raw): ?string
    {
        try {
            $apiUrl = $this->stalwart->getApiUrl();
            if ($apiUrl === null) return null;

            $url = \rtrim($apiUrl, '/') . '/jmap/upload/' . \rawurlencode($accountId) . '/';

            $client = \OCP\Server::get(\OCP\Http\Client\IClientService::class)->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearer,
                    'Content-Type' => 'message/rfc822',
                    'Accept' => 'application/json',
                ],
                'body' => $raw,
                'timeout' => 15,
                'connect_timeout' => 10,
            ]);

            $decoded = \json_decode((string) $response->getBody(), true);
            if (\is_array($decoded) && isset($decoded['blobId'])) {
                return (string) $decoded['blobId'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('ExternalImapFetch: blob upload failed', ['exception' => $e]);
            return null;
        }
    }
}
