<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Dashboard;

use OCA\SouveraMail\Util\EngineHelper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Souvera Mail dashboard widget — shows the user's INBOX, configurable per
 * user between "unread only" (default) and "all messages" via personal
 * setting `souvera_mail/dashboard-mode`. The setting is persisted with Nextcloud's
 * standard user-config storage, so operators can also set it from CLI:
 *
 *   occ user:setting <uid> souvera_mail dashboard-mode all
 *   occ user:setting <uid> souvera_mail dashboard-mode unread
 *
 * Each item links to the message inside the Souvera Mail app via the
 * engine's hash-router (`#/mailbox/INBOX/m<UID>`), so a click goes straight
 * to the open message.
 */
class UnreadMailWidget implements IAPIWidgetV2, IIconWidget, IReloadableWidget
{
    public const WIDGET_ID = 'souvera_mail-unread';
    public const MODE_UNREAD = 'unread';
    public const MODE_ALL = 'all';
    // Operator decision 2026-02-19 with screenshot attachment: default the
    // widget to "all recent mails" (was "unread only"). Rationale: the
    // widget looked empty for most users because the vast majority of
    // inboxes are fully read most of the time. The personal setting
    // `souvera_mail/dashboard-mode` still lets a user pin it to "unread"
    // via `occ user:setting <uid> souvera_mail dashboard-mode unread`.
    public const MODE_DEFAULT = self::MODE_ALL;
    public const USER_CONFIG_MODE = 'dashboard-mode';

    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getId(): string
    {
        return self::WIDGET_ID;
    }

    public function getTitle(): string
    {
        // Operator decision 2026-02-19 (with screenshot): drop the
        // "· Inbox" suffix so the widget header matches the Souvera
        // Shield / Souvera Contacts pattern of a single, brand-clean
        // name.  Mode is still communicated via the emptyContentMessage.
        return $this->l10n->t('Souvera Mail');
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getIconClass(): string
    {
        return 'icon-mail';
    }

    public function getUrl(): ?string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('souvera_mail.page.index')
        );
    }

    public function load(): void
    {
        // Load the dashboard-widget enhancer bundle on every dashboard
        // render. It does two things that can't be expressed via the
        // IAPIWidgetV2 JSON contract:
        //
        //   1. Injects a large ✓ checkmark icon into the NcEmptyContent
        //      slot of *our* widget when items are empty, matching the
        //      Souvera Shield "Mail-Quarantäne" empty state (2026-02-19
        //      operator request with screenshot).
        //   2. Applies theme-aware colour rules for the widget icon:
        //      Light-mode → black, Dark-mode → white (via CSS filter on
        //      the <img> that NC injects for `getIconUrl()`).  Also
        //      forces the App-Menu / Nav icon to the operator's spec:
        //      Light-mode → white, Dark-mode → black.
        //
        // Both files are registered on every dashboard load — cheap
        // because the JS is a MutationObserver that idles unless our
        // widget re-renders, and the CSS is <1 KB.
        \OCP\Util::addStyle('souvera_mail', 'dashboard-widget');
        \OCP\Util::addScript('souvera_mail', 'dashboard-widget-enhancer');
    }

    private function resolveMode(string $userId): string
    {
        $mode = $this->config->getUserValue(
            $userId,
            'souvera_mail',
            self::USER_CONFIG_MODE,
            self::MODE_DEFAULT,
        );
        return $mode === self::MODE_ALL ? self::MODE_ALL : self::MODE_UNREAD;
    }

    /**
     * @param string $userId
     * @param string|null $since
     * @param int $limit
     */
    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
    {
        $mode = $this->resolveMode($userId);

        try {
            $this->engineHelper->startApp();
            $oActions = \Smail\Engine\Api::Actions();
            $oAccount = $oActions->getMainAccountFromToken(false);
            if (!$oAccount) {
                $oAccount = $oActions->getAccountFromToken(false);
            }
            if (!$oAccount) {
                $this->logger->info(
                    'Souvera Mail widget: no engine session — showing fallback',
                    ['app' => 'souvera_mail']
                );
                return new WidgetItems([], $this->l10n->t('Open Souvera Mail to connect'));
            }

            $oConfig = $oActions->Config();

            $oParams = new \Smail\Mail\Client\MessageListParams();
            $oParams->sFolderName = 'INBOX';
            $oParams->sSearch = $mode === self::MODE_UNREAD ? 'unseen' : '';
            $oParams->oCacher = ($oConfig->Get('cache', 'enable', true) && $oConfig->Get('cache', 'server_uids', false))
                ? $oActions->Cacher($oAccount) : null;
            $oParams->bUseSort = !!$oConfig->Get('labs', 'use_imap_sort', true);
            $oParams->iLimit = $limit;

            $oMailClient = $oActions->MailClient();
            if (!$oMailClient->ImapClient()->IsLoggined()) {
                $oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oConfig);
            }

            $MessageCollection = $oMailClient->MessageList($oParams);

            $items = [];
            $baseURL = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('souvera_mail.page.index')
            ) . '#';

            foreach ($MessageCollection as $Message) {
                $items[] = new WidgetItem(
                    $Message->From()->ToString(),
                    $Message->Subject(),
                    $baseURL . '/mailbox/INBOX/m' . $Message->Uid(),
                    $this->urlGenerator->imagePath('souvera_mail', 'app.svg'),
                    $Message->ETag('')
                );
            }

            if (empty($items)) {
                // Match the Souvera Shield "Ihre Quarantäne ist derzeit
                // leer" pattern: put the message in `emptyContentMessage`
                // (2nd arg) so NcEmptyContent renders it centred with an
                // icon slot — NOT in `halfEmptyContentMessage` (3rd arg,
                // which only shows *below* an existing item list and
                // stays invisible when items are empty).
                $empty = $mode === self::MODE_UNREAD
                    ? $this->l10n->t('No unread mail')
                    : $this->l10n->t('Your mailbox is currently empty');
                return new WidgetItems([], $empty, '');
            }

            return new WidgetItems($items);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail widget error: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new WidgetItems([], $this->l10n->t('Open Souvera Mail to connect'));
        }
    }

    public function getReloadInterval(): int
    {
        return 120;
    }

    public function getIconUrl(): string
    {
        // Dashboard-Widget-only icon (BLACK on Light-mode / WHITE on
        // Dark-mode via CSS filter in css/dashboard-widget.css).
        //
        // We deliberately serve a SEPARATE SVG (`app-widget.svg`) from
        // the App-Popover / Nav-Menu icon (`app.svg`). Why: the two
        // rendering pipelines demand OPPOSITE base colours —
        //
        //   • `app.svg` is white → App-Popover shows white-in-blue
        //     (Light) and NC's auto-invert flips it to black (Dark).
        //   • `app-widget.svg` is black → Widget header shows black on
        //     white (Light), and our own CSS filter inverts it to
        //     white on dark (Dark) inside `.panel--header`.
        //
        // Using two files eliminates the risk of a CSS filter or
        // `currentColor` gymnastic accidentally swapping the two
        // (which is exactly what happened v0.14.22 → v0.14.26 while
        // debugging the operator's "verdreht" report).
        return $this->urlGenerator->imagePath('souvera_mail', 'app-widget.svg');
    }
}
