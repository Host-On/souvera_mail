<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('souvera_mail', 'personal-settings');
?>
<style>
#souvera-mail-settings-page {
    max-width: 760px;
    margin: 0 auto;
    padding: 32px 32px 64px;
    color: var(--color-main-text, #1c1c1c);
}
#souvera-mail-settings-page .breadcrumb {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
    padding: 6px 14px;
    border-radius: 100px;
    background: var(--color-background-hover, rgba(0, 0, 0, 0.04));
    color: var(--color-text-maxcontrast, #777);
    text-decoration: none;
    font-size: 13px;
    transition: background 120ms ease;
}
#souvera-mail-settings-page .breadcrumb:hover {
    background: var(--color-background-dark, rgba(0, 0, 0, 0.08));
}
#souvera-mail-settings-page h1 {
    margin: 0 0 4px;
    font-size: 32px;
    letter-spacing: -0.025em;
    font-weight: 600;
}
#souvera-mail-settings-page .lead {
    margin: 0 0 32px;
    color: var(--color-text-maxcontrast, #777);
    font-size: 14px;
    line-height: 1.55;
}
#souvera-mail-settings-page section {
    background: var(--color-main-background, #fff);
    border: 1px solid var(--color-border, #ededed);
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
}
#souvera-mail-settings-page section h2 {
    margin: 0 0 4px;
    font-size: 17px;
    font-weight: 600;
    letter-spacing: -0.01em;
}
#souvera-mail-settings-page section .section-desc {
    margin: 0 0 16px;
    color: var(--color-text-maxcontrast, #777);
    font-size: 13px;
    line-height: 1.55;
}
</style>
<div id="souvera-mail-settings-page">
    <a href="<?php p($_['backUrl']); ?>" class="breadcrumb" data-testid="souvera-mail-settings-back">
        &larr; <?php p($l->t('Back to inbox')); ?>
    </a>
    <h1><?php p($_['brandName'] . ' · ' . $l->t('Settings')); ?></h1>
    <p class="lead">
        <?php p($l->t('Manage your dashboard widget, App Passwords for legacy clients, and other per-account preferences.')); ?>
    </p>

    <section>
        <h2><?php p($l->t('Dashboard widget')); ?></h2>
        <p class="section-desc">
            <?php p($l->t('Choose what the Souvera Mail dashboard widget shows on your Nextcloud homepage. Clicking a message opens it directly in the inbox.')); ?>
        </p>
        <form id="souvera-mail-dashboard-mode-form"
              data-endpoint="<?php p($_['dashboardModeUrl']); ?>"
              data-label-unread="<?php p($l->t('Saved · Unread only')); ?>"
              data-label-all="<?php p($l->t('Saved · Full inbox')); ?>"
              data-label-fail="<?php p($l->t('Save failed')); ?>"
              style="display:flex;flex-direction:column;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="radio"
                       name="souvera-mail-dashboard-mode"
                       value="<?php p($_['dashboardModeUnread']); ?>"
                       data-testid="souvera-mail-dashboard-mode-unread"
                       <?php if ($_['dashboardMode'] === $_['dashboardModeUnread']) { p('checked'); } ?> />
                <span><?php p($l->t('Only unread messages (default)')); ?></span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="radio"
                       name="souvera-mail-dashboard-mode"
                       value="<?php p($_['dashboardModeAll']); ?>"
                       data-testid="souvera-mail-dashboard-mode-all"
                       <?php if ($_['dashboardMode'] === $_['dashboardModeAll']) { p('checked'); } ?> />
                <span><?php p($l->t('Full inbox (latest messages)')); ?></span>
            </label>
            <span id="souvera-mail-dashboard-mode-status"
                  data-testid="souvera-mail-dashboard-mode-status"
                  role="status"
                  aria-live="polite"
                  style="font-size:12px;color:var(--color-text-maxcontrast,#888);min-height:16px;"></span>
        </form>
    </section>

    <section id="souvera-mail-app-passwords-section"
             data-list-url="<?php p($_['appPasswordsListUrl']); ?>"
             data-create-url="<?php p($_['appPasswordsCreateUrl']); ?>"
             data-destroy-url-template="<?php p($_['appPasswordsDestroyUrlTemplate']); ?>"
             data-available="<?php p($_['appPasswordsAvailable'] ? '1' : '0'); ?>"
             data-label-load-fail="<?php p($l->t('Could not load app passwords')); ?>"
             data-label-create-fail="<?php p($l->t('Could not create app password')); ?>"
             data-label-revoke-fail="<?php p($l->t('Could not revoke app password')); ?>"
             data-label-confirm-revoke="<?php p($l->t('Revoke this app password? Devices using it will lose mailbox access immediately.')); ?>"
             data-label-copy="<?php p($l->t('Copy')); ?>"
             data-label-copied="<?php p($l->t('Copied!')); ?>"
             data-label-empty="<?php p($l->t('No app passwords yet.')); ?>"
             data-label-revoke="<?php p($l->t('Revoke')); ?>"
             data-label-created-warning="<?php p($l->t('Save this password now — Souvera Mail can never show it again.')); ?>">
        <h2><?php p($l->t('App passwords (IMAP / POP3 / SMTP)')); ?></h2>
        <p class="section-desc">
            <?php p($l->t('Generate one app password per device or mail client (Thunderbird, Apple Mail, iOS, Outlook, …). Each password grants the same access as your account but can be revoked individually. Older clients without OAuth/OIDC support use these in place of your Nextcloud password.')); ?>
        </p>

        <?php if (!$_['appPasswordsAvailable']) { ?>
            <p style="padding:12px;border-radius:8px;background:var(--color-warning-hover,#fff3cd);color:var(--color-warning,#856404);font-size:13px;">
                <?php p($l->t('App passwords are not available — Souvera Central and the Stalwart API URL must be configured by the administrator first.')); ?>
            </p>
        <?php } else { ?>
            <form id="souvera-mail-app-password-create-form"
                  style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                <input type="text"
                       name="description"
                       data-testid="souvera-mail-app-password-description"
                       placeholder="<?php p($l->t('Description, e.g. Thunderbird Desktop')); ?>"
                       maxlength="120"
                       required
                       style="flex:1;min-width:240px;padding:8px 12px;border-radius:8px;border:1px solid var(--color-border,#ccc);" />
                <button type="submit"
                        data-testid="souvera-mail-app-password-create-button"
                        style="padding:8px 18px;border-radius:100px;border:0;
                               background:var(--color-primary-element,#0077C7);color:#fff;
                               font-weight:500;cursor:pointer;">
                    <?php p($l->t('Create')); ?>
                </button>
            </form>

            <div id="souvera-mail-app-password-newly-created"
                 data-testid="souvera-mail-app-password-newly-created"
                 role="status"
                 aria-live="polite"
                 style="display:none;margin-bottom:16px;padding:14px;border-radius:8px;
                        background:var(--color-success-hover,#e8f5e9);border:1px solid var(--color-success,#388e3c);">
            </div>

            <table id="souvera-mail-app-passwords-table"
                   data-testid="souvera-mail-app-passwords-table"
                   style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:6px 8px;font-size:12px;color:var(--color-text-maxcontrast,#888);text-transform:uppercase;letter-spacing:0.04em;">
                            <?php p($l->t('Description')); ?>
                        </th>
                        <th style="text-align:left;padding:6px 8px;font-size:12px;color:var(--color-text-maxcontrast,#888);text-transform:uppercase;letter-spacing:0.04em;">
                            <?php p($l->t('Created')); ?>
                        </th>
                        <th style="text-align:right;padding:6px 8px;font-size:12px;color:var(--color-text-maxcontrast,#888);text-transform:uppercase;letter-spacing:0.04em;">
                            <?php p($l->t('Actions')); ?>
                        </th>
                    </tr>
                </thead>
                <tbody id="souvera-mail-app-passwords-tbody"
                       data-testid="souvera-mail-app-passwords-tbody">
                    <tr><td colspan="3" style="padding:12px;color:var(--color-text-maxcontrast,#888);font-size:13px;">
                        <?php p($l->t('Loading…')); ?>
                    </td></tr>
                </tbody>
            </table>
        <?php } ?>
    </section>
</div>
