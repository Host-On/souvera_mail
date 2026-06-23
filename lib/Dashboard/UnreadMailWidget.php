<?php

declare(strict_types=1);

namespace OCA\Smail\Dashboard;

use OCA\Smail\Util\EngineHelper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class UnreadMailWidget implements IAPIWidgetV2, IIconWidget, IReloadableWidget
{
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
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
        return $this->l10n->t('Unread mail');
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
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('smail.page.index'));
    }

    public function load(): void
    {
    }

    /**
     * @param string $userId
     * @param string|null $since
     * @param int $limit
     */
    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
    {
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
            $oParams->sSearch = 'unseen';
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
            $baseURL = $this->urlGenerator->linkToRoute('smail.page.index') . '#';

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
                return new WidgetItems([], '', $this->l10n->t('No unread mail'));
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
