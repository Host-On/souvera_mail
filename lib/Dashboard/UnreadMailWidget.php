<?php

declare(strict_types=1);

namespace OCA\Smail\Dashboard;

use OCA\Smail\Util\EngineHelper;
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
 * setting `smail/dashboard-mode`. The setting is persisted with Nextcloud's
 * standard user-config storage, so operators can also set it from CLI:
 *
 *   occ user:setting <uid> smail dashboard-mode all
 *   occ user:setting <uid> smail dashboard-mode unread
 *
 * Each item links to the message inside the Souvera Mail app via the
 * engine's hash-router (`#/mailbox/INBOX/m<UID>`), so a click goes straight
 * to the open message.
 */
class UnreadMailWidget implements IAPIWidgetV2, IIconWidget, IReloadableWidget
{
    public const MODE_UNREAD = 'unread';
    public const MODE_ALL = 'all';
    public const MODE_DEFAULT = self::MODE_UNREAD;
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
        return 'smail-unread';
    }

    public function getTitle(): string
    {
        // Title is mode-agnostic — the widget item list itself communicates
        // whether the user is currently in "unread only" or "all" mode via
        // the empty-content message.
        return $this->l10n->t('Souvera Mail · Inbox');
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
            $this->urlGenerator->linkToRoute('smail.page.index')
        );
    }

    public function load(): void
    {
    }

    private function resolveMode(string $userId): string
    {
        $mode = $this->config->getUserValue(
            $userId,
            'smail',
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
                    ['app' => 'smail']
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
                $this->urlGenerator->linkToRoute('smail.page.index')
            ) . '#';

            foreach ($MessageCollection as $Message) {
                $items[] = new WidgetItem(
                    $Message->From()->ToString(),
                    $Message->Subject(),
                    $baseURL . '/mailbox/INBOX/m' . $Message->Uid(),
                    $this->urlGenerator->imagePath('smail', 'logo-64x64.png'),
                    $Message->ETag('')
                );
            }

            if (empty($items)) {
                $empty = $mode === self::MODE_UNREAD
                    ? $this->l10n->t('No unread mail')
                    : $this->l10n->t('Inbox is empty');
                return new WidgetItems([], '', $empty);
            }

            return new WidgetItems($items);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail widget error: ' . $e->getMessage(),
                ['app' => 'smail', 'exception' => $e]
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
        return $this->urlGenerator->imagePath('smail', 'logo-64x64.png');
    }
}
