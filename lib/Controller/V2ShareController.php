<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

/**
 * Ordner-Freigabe über die native Stalwart-JMAP-Sharing-API:
 *
 *   Mailbox/set update: { <mailboxId>: { shareWith: { <grantee-accountId>:
 *     { mayReadItems: true, … } } } }
 *
 * Rechte-Mapping:
 *   lesend     → mayReadItems
 *   schreibend → mayReadItems + mayAddItems + mayRemoveItems
 *                + maySetSeen + maySetKeywords
 *
 * Der Empfänger sieht den geteilten Ordner automatisch: die JMAP-Session
 * listet den Account des Teilenden (V2SharedController::list lädt die
 * Mailboxen daraus in die Sidebar). Kein Stalwart-Patch nötig.
 */
class V2ShareController extends Controller {

	/** Lesend: nur Inhalte sehen. */
	private const RIGHTS_READ = ['mayReadItems' => true];
	/** Schreibend: lesen + einordnen/verschieben/als gelesen markieren. */
	private const RIGHTS_WRITE = [
		'mayReadItems' => true,
		'mayAddItems' => true,
		'mayRemoveItems' => true,
		'maySetSeen' => true,
		'maySetKeywords' => true,
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private StalwartUserContext $userContext,
		private StalwartAdminService $stalwartAdmin,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private INotificationManager $notifications,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * GET /api/v2/share/users?q=… — Empfänger-Suche (NC-User, max. 20).
	 */
	#[NoAdminRequired]
	public function searchUsers(): JSONResponse {
		$q = \trim((string) ($this->request->getParam('q') ?? ''));
		if (\mb_strlen($q) < 2) {
			return new JSONResponse(['users' => []]);
		}
		$out = [];
		foreach ($this->userManager->searchDisplayName($q, 20) as $u) {
			$out[] = [
				'uid' => $u->getUID(),
				'displayName' => $u->getDisplayName(),
				'email' => $u->getEMailAddress() ?? '',
			];
		}
		return new JSONResponse(['users' => $out]);
	}

	/**
	 * GET /api/v2/share?mailboxId=… — aktuelle Freigaben eines Ordners.
	 */
	#[NoAdminRequired]
	public function list(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], 401);
		}
		$mailboxId = \trim((string) ($this->request->getParam('mailboxId') ?? ''));
		if ($mailboxId === '') {
			return new JSONResponse(['error' => 'mailboxId required'], 400);
		}
		try {
			$bearer = $this->userContext->resolveBearer($user->getUID());
			$accountId = $this->userContext->resolveAccountId($user->getUID());
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'Stalwart session failed: ' . $e->getMessage()], 502);
		}

		$result = $this->stalwartAdmin->jmapCall($bearer, [
			['Mailbox/get', [
				'accountId' => $accountId,
				'ids' => [$mailboxId],
				'properties' => ['name', 'shareWith', 'myRights'],
			], 'm0'],
		], ['urn:ietf:params:jmap:mail']);
		if (isset($result['error'])) {
			return new JSONResponse(['error' => 'Mailbox/get failed: ' . $result['error']], 502);
		}
		$mailbox = $result['methodResponses'][0][1]['list'][0] ?? null;
		if (!\is_array($mailbox)) {
			return new JSONResponse(['error' => 'Mailbox nicht gefunden'], 404);
		}

		$shareWith = $mailbox['shareWith'] ?? [];
		$grants = [];
		foreach (($mailbox['shareWith'] ?? []) as $accountIdKey => $rights) {
			$email = $this->stalwartAdmin->lookupPrincipalEmailByAccountId(
				$this->principalIdFromAccountKey((string) $accountIdKey)
			) ?? '';
			$granteeUid = '';
			if ($email !== '') {
				foreach ($this->userManager->getByEmail($email) as $u) {
					$granteeUid = $u->getUID();
					break;
				}
			}
			$grants[] = [
				'accountId' => (string) $accountIdKey,
				'granteeUid' => $granteeUid,
				'email' => $email,
				'rights' => \is_array($rights) ? $rights : [],
			];
		}

		return new JSONResponse([
			'mailboxId' => $mailboxId,
			'name' => $mailbox['name'] ?? '',
			'myRights' => $mailbox['myRights'] ?? [],
			'grants' => $grants,
		]);
	}

	/**
	 * POST /api/v2/share — Freigabe erteilen (oder Rechte ändern).
	 * Body: {mailboxId, granteeUid, permission: 'read'|'write', includeChildren?: bool}
	 */
	#[NoAdminRequired]
	public function share(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], 401);
		}
		$body = \json_decode(\file_get_contents('php://input'), true) ?? [];
		$mailboxId = \trim((string) ($body['mailboxId'] ?? ''));
		$granteeUid = \trim((string) ($body['granteeUid'] ?? ''));
		$permission = \in_array(($body['permission'] ?? ''), ['read', 'write'], true)
			? (string) $body['permission'] : 'read';
		$includeChildren = (bool) ($body['includeChildren'] ?? false);

		if ($mailboxId === '' || $granteeUid === '') {
			return new JSONResponse(['error' => 'mailboxId und granteeUid erforderlich'], 400);
		}
		if ($granteeUid === $user->getUID()) {
			return new JSONResponse(['error' => 'Eigene Ordner können nicht mit sich selbst geteilt werden'], 400);
		}
		$grantee = $this->userManager->get($granteeUid);
		if ($grantee === null) {
			return new JSONResponse(['error' => 'Empfänger nicht gefunden'], 404);
		}

		// Recipient notification (der Empfänger sieht den Ordner in seiner Sidebar)
		$this->notifyShare($grantee->getUID(), $user->getDisplayName());

		return $this->applyShare($user, $mailboxId, $granteeUid, $permission, $includeChildren);
	}

	/**
	 * DELETE /api/v2/share — Freigabe entziehen.
	 * Query: mailboxId, granteeUid, includeChildren?
	 */
	#[NoAdminRequired]
	public function revoke(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], 401);
		}
		$mailboxId = \trim((string) ($this->request->getParam('mailboxId') ?? ''));
		$granteeUid = \trim((string) ($this->request->getParam('granteeUid') ?? ''));
		$granteeAccountId = \trim((string) ($this->request->getParam('granteeAccountId') ?? ''));
		if ($mailboxId === '' || ($granteeUid === '' && $granteeAccountId === '')) {
			return new JSONResponse(['error' => 'mailboxId und granteeUid/granteeAccountId erforderlich'], 400);
		}

		return $this->applyShare($user, $mailboxId, $granteeUid, 'revoke', false, $granteeAccountId);
	}

	/**
	 * Wendet die Freigabe (oder den Entzug) auf den Ordner + optional alle
	 * Kinder an. Der Ziel-User wird über seine Stalwart-AccountId adressiert.
	 */
	private function applyShare(\OCP\IUser $owner, string $mailboxId, string $granteeUid, string $permission, bool $includeChildren, string $granteeAccountId = ''): JSONResponse {
		try {
			$bearer = $this->userContext->resolveBearer($owner->getUID());
			$accountId = $this->userContext->resolveAccountId($owner->getUID());
			$granteeAccountId = $granteeAccountId !== ''
				? $granteeAccountId
				: $this->userContext->resolveAccountId($granteeUid);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'Stalwart session failed: ' . $e->getMessage()], 502);
		}

		// Mailbox + Kinder laden (für den rekursiven Fall)
		$mailboxResult = $this->stalwartAdmin->jmapCall($bearer, [
			['Mailbox/get', [
				'accountId' => $accountId,
				'ids' => [$mailboxId],
				'properties' => ['name', 'parentId', 'role'],
			], 'm0'],
			['Mailbox/query', [
				'accountId' => $accountId,
				'sort' => [['property' => 'sortOrder']],
				'limit' => 200,
			], 'm1'],
		], ['urn:ietf:params:jmap:mail']);
		if (isset($mailboxResult['error'])) {
			return new JSONResponse(['error' => 'Mailbox lookup failed: ' . $mailboxResult['error']], 502);
		}
		$mailboxGet = $this->extractResponse($mailboxResult, 'Mailbox/get', 'm0');
		$mailbox = $mailboxGet['list'][0] ?? null;
		if (!\is_array($mailbox)) {
			return new JSONResponse(['error' => 'Mailbox nicht gefunden'], 404);
		}
		$allMailboxes = $this->extractResponse($mailboxResult, 'Mailbox/query', 'm1')['list'] ?? [];

		$targets = [$mailbox];
		if ($includeChildren) {
			$targets = \array_merge($targets, $this->collectChildren($mailbox, $allMailboxes));
		}

		// Bestehendes shareWith lesen und den Grantee setzen/entfernen —
		// pro Ordner EIN Mailbox/set-Update mit dem GESAMTEN neuen shareWith
		// (ein anderer Grant bleibt unberührt).
		$updated = 0;
		$errors = [];
		foreach ($targets as $mb) {
			$mbId = (string) ($mb['id'] ?? '');
			if ($mbId === '') { continue; }
			$currentShareWith = \is_array($mb['shareWith'] ?? null) ? $mb['shareWith'] : [];

			if ($permission === 'revoke') {
				// Grant entfernen: Schlüssel auf null setzen (JMAP-Share-Patch)
				$newShareWith = $currentShareWith;
				unset($newShareWith[$granteeAccountId]);
			} else {
				$rights = $permission === 'write' ? self::RIGHTS_WRITE : self::RIGHTS_READ;
				$newShareWith = $currentShareWith;
				$newShareWith[$granteeAccountId] = $rights;
			}

			$updateObject = \array_merge(
				['name' => $mailbox['name'] ?? ''],
				['shareWith' => \count($newShareWith) > 0 ? $newShareWith : null],
			);
			// name wird von Stalwart beim Update NICHT geändert — nur shareWith
			// ist relevant; der Rest bleibt unberührt. (Das vollständige Objekt
			// würde Namen/Reihenfolge überschreiben.)
			$updateObject = ['shareWith' => \count($newShareWith) > 0 ? $newShareWith : null];

			$result = $this->stalwartAdmin->jmapCall($bearer, [
				['Mailbox/set', [
					'accountId' => $accountId,
					'update' => [$mbId => $updateObject],
				], 'u0'],
			], ['urn:ietf:params:jmap:mail']);
			if (isset($result['error'])) {
				$errors[] = $result['error'];
				continue;
			}
			$notUpdated = $result['methodResponses'][0][1]['notUpdated'][$mbId] ?? null;
			if ($notUpdated !== null) {
				$errors[] = \json_encode($notUpdated, JSON_UNESCAPED_SLASHES);
				continue;
			}
			$updated++;
		}

		if ($errors !== []) {
			return new JSONResponse([
				'error' => 'Stalwart hat die Freigabe-Änderung abgelehnt: ' . \mb_substr(\json_encode($errors, JSON_UNESCAPED_SLASHES), 0, 200),
			], 502);
		}

		return new JSONResponse([
			'success' => true,
			'permission' => $permission,
			'folders' => $updated,
		]);
	}

	/** @return list<array> alle Kinder (rekursiv) der Mailbox */
	private function collectChildren(array $mailbox, array $allMailboxes): array {
		$out = [];
		$parentId = (string) ($mailbox['id'] ?? '');
		$guard = 0;
		$changed = true;
		$pool = $allMailboxes;
		$collected = [$parentId => true];
		while ($changed && $guard++ < 10) {
			$changed = false;
			foreach ($pool as $mb) {
				$pid = (string) ($mb['parentId'] ?? '');
				if ($pid !== '' && isset($collected[$pid]) && !isset($collected[(string) ($mb['id'] ?? '')])) {
					$collected[(string) ($mb['id'] ?? '')] = true;
					$out[] = $mb;
					$changed = true;
				}
			}
		}
		return $out;
	}

	private function extractResponse(array $jmapResponse, string $method, string $callId): array {
		foreach (($jmapResponse['methodResponses'] ?? []) as $resp) {
			if (($resp[0] ?? '') === $method && ($resp[2] ?? '') === $callId) {
				return \is_array($resp[1] ?? null) ? $resp[1] : [];
			}
		}
		return [];
	}

	private function principalIdFromAccountKey(string $accountKey): int {
		// Stalwart-JMAP-Ids sind base32 des numerischen Document-Ids
		// (Alphabet abcdefghijklmnopqrstuvwxyz792013) — zur Rückauflösung
		// über lookupPrincipalEmailByAccountId benötigen wir die Zahl.
		$alphabet = 'abcdefghijklmnopqrstuvwxyz792013';
		$value = 0;
		foreach (\str_split(\strtolower($accountKey)) as $ch) {
			$pos = \strpos($alphabet, $ch);
			if ($pos === false) { continue; }
			$value = ($value << 5) | $pos;
		}
		return $value;
	}

	private function notifyShare(string $granteeUid, string $ownerName): void {
		try {
			// NC-Notification-Subject: max. 64 BYTE (setSubject-Validierung)
			$subject = \mb_strcut('Ordner-Freigabe von ' . $ownerName, 0, 64);
			$notification = $this->notifications->createNotification();
			$notification->setApp('souvera_mail')
				->setUser($granteeUid)
				->setDateTime(new \DateTime())
				->setObject('share', \md5($granteeUid . $ownerName))
				->setSubject($subject);
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			// Benachrichtigung ist best-effort
		}
	}
}
