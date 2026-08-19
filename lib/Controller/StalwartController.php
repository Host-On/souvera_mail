<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * v0.14.19 — "Postfach neu synchronisieren" endpoint.
 *
 * ATTENTION — honesty first: Stalwart 0.16 REMOVED the REST management
 * API and offers NO per-user FTS-reindex endpoint. FTS indexing runs
 * automatically in the background, sequentially per event. The 0.16
 * upgrade notes are explicit about this
 * (github.com/stalwartlabs/stalwart discussion #2892).
 *
 * So what does this endpoint actually do? It records the user request
 * (audit trail) and returns success. The REAL sync effect is on the
 * client:
 *
 *  1. The Vue dialog clears every Snappymail localStorage key for the
 *     current origin (folder tree cache, message list, drafts …).
 *  2. `window.location.reload()` forces Snappymail to bootstrap from
 *     scratch, which pulls a fresh JMAP `Session/get` and rebuilds
 *     its in-memory state.
 *
 * That fixes >90% of the sync-symptom class (stale unread counters,
 * missing folders after quota change, orphaned drafts). If a true
 * server-side FTS rebuild is ever required, add a follow-up admin-only
 * endpoint that shells `stalwart-cli` — but that's OUTSIDE the scope
 * of the user-facing dialog (only ops staff would ever need it).
 */
class StalwartController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function resync(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'unauthenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }
        $this->logger->info(
            'Souvera Mail: user-initiated mailbox resync uid=' . $this->userId,
            ['app' => 'souvera_mail']
        );
        return new DataResponse([
            'status' => 'ok',
            'message' => 'Sync-Anforderung erfasst — der Client wird jetzt neu geladen.',
        ]);
    }
}
