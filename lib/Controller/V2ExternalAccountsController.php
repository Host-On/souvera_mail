<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\ExternalAccountService;
use OCA\SouveraMail\Service\ExternalImapService;
use OCA\SouveraMail\Service\ExternalSmtpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * External IMAP/SMTP account management for v2 Vue-3 frontend.
 *
 * Handles CRUD for external accounts stored in encrypted app-config.
 * Connection testing uses PHP's native IMAP functions.
 */
class V2ExternalAccountsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ExternalAccountService $accountService,
        private ExternalImapService $imapService,
        private ExternalSmtpService $smtpService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/external/accounts
     *
     * List all external accounts for the current user.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $accounts = $this->accountService->listForUser($uid);
            return new JSONResponse(['accounts' => $accounts]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts
     *
     * Add a new external account.
     * Body: {email, imap_host, imap_port, imap_ssl, smtp_host, smtp_port,
     *        smtp_ssl, username, password, provider}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        try {
            $account = $this->accountService->add($uid, $body);
            return new JSONResponse(['account' => $account], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/external/accounts/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->accountService->delete($uid, $id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts/{id}/test
     *
     * Test IMAP connection for an account.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }
        $result = $this->accountService->testImap($account);
        return new JSONResponse($result);
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts/test-connection
     *
     * Test IMAP credentials WITHOUT saving the account. The form data is
     * validated server-side first, then the connection is attempted.
     * The add flow requires a successful test before it saves.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function testConnection(): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];

        // Validate the same fields add() would require.
        $email = \strtolower(\trim((string) ($body['email'] ?? '')));
        if ($email === '' || !\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JSONResponse(['ok' => false, 'error' => 'Invalid email address'], Http::STATUS_BAD_REQUEST);
        }
        $entry = [
            'email' => $email,
            'imap_host' => \trim((string) ($body['imap_host'] ?? '')),
            'imap_port' => (int) ($body['imap_port'] ?? 993),
            'imap_ssl' => (string) ($body['imap_ssl'] ?? 'ssl'),
            'smtp_host' => \trim((string) ($body['smtp_host'] ?? '')),
            'smtp_port' => (int) ($body['smtp_port'] ?? 465),
            'smtp_ssl' => (string) ($body['smtp_ssl'] ?? 'ssl'),
            'username' => \trim((string) ($body['username'] ?? $email)),
            'password' => (string) ($body['password'] ?? ''),
        ];
        if ($entry['imap_host'] === '') {
            return new JSONResponse(['ok' => false, 'error' => 'IMAP host is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($entry['password'] === '') {
            return new JSONResponse(['ok' => false, 'error' => 'Password is required'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->accountService->testImap($entry);
        return new JSONResponse($result);
    }

    /**
     * GET /apps/souvera_mail/api/v2/external/accounts/{id}/folders
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folders(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }
        $result = $this->imapService->folders($account);
        if (!$result['ok']) {
            $this->logger->warning('ExternalImapService folders failed: ' . ($result['error'] ?? ''), ['app' => 'souvera_mail']);
        }
        return new JSONResponse($result);
    }

    /**
     * GET /apps/souvera_mail/api/v2/external/accounts/{id}/messages
     * Query: folder, offset, limit
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function messages(string $id, string $folder = 'INBOX', int $offset = 0, int $limit = 50): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }
        $folder = \trim($folder) === '' ? 'INBOX' : \trim($folder);
        $limit = \max(1, \min(200, $limit));
        return new JSONResponse($this->imapService->messages($account, $folder, $offset, $limit));
    }

    /**
     * GET /apps/souvera_mail/api/v2/external/accounts/{id}/message/{messageUid}
     * Query: folder
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function message(string $id, int $messageUid, string $folder = 'INBOX'): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }
        $folder = \trim($folder) === '' ? 'INBOX' : \trim($folder);
        return new JSONResponse($this->imapService->message($account, $folder, $messageUid));
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts/{id}/send
     * Body: {fromName, to:[], cc:[], bcc:[], subject, bodyHtml, bodyPlain}
     *
     * Sends through the account's SMTP server. Recipients are passed
     * verbatim (no alias checks apply — the account itself is the sender).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function send(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $to = \is_array($body['to'] ?? null) ? $body['to'] : [];
        $cc = \is_array($body['cc'] ?? null) ? $body['cc'] : [];
        $bcc = \is_array($body['bcc'] ?? null) ? $body['bcc'] : [];
        $subject = \trim((string) ($body['subject'] ?? ''));
        $bodyHtml = \trim((string) ($body['bodyHtml'] ?? ''));
        $bodyPlain = \trim((string) ($body['bodyPlain'] ?? ''));
        $fromName = \trim((string) ($body['fromName'] ?? ''));

        if ($to === [] && $cc === [] && $bcc === []) {
            return new JSONResponse(['error' => 'No recipients'], 400);
        }

        try {
            $this->smtpService->send(
                $account,
                (string) ($account['email'] ?? ''),
                $fromName,
                $to, $cc, $bcc,
                $subject, $bodyHtml, $bodyPlain,
            );
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->warning('ExternalSmtpService send failed: ' . $e->getMessage(), [
                'app' => 'souvera_mail', 'exception' => $e,
            ]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }
    }

    private function getUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : null;
    }
}
