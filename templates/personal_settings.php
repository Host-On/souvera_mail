<?php
\OCP\Util::addScript('smail', 'personal-settings');
?>
<div class="section">
    <h2><?php p($_['brandName'] . ' ' . $l->t('Settings')); ?></h2>
    <p style="margin-bottom: 12px">
        <a href="<?php p($_['settingsUrl']); ?>"
           style="display:inline-flex;align-items:center;gap:6px;
                  padding:8px 16px;border-radius:100px;
                  background:var(--color-primary-element,#0077C7);
                  color:#fff;text-decoration:none;font-weight:500;">
            <?php p($l->t('Identities & Signatures')); ?>
        </a>
    </p>
    <p style="color: var(--color-text-maxcontrast, #888); font-size: 13px;">
        <?php p($l->t('Manage sender addresses, display names, reply-to and signatures.')); ?>
    </p>
</div>

<div class="section">
    <h2><?php p($l->t('Dashboard widget')); ?></h2>
    <p style="margin-bottom: 12px; color: var(--color-text-maxcontrast, #888); font-size: 13px;">
        <?php p($l->t('Choose what the Souvera Mail dashboard widget shows. Clicking a message opens it directly in the inbox.')); ?>
    </p>
    <form id="smail-dashboard-mode-form"
          data-endpoint="<?php p($_['dashboardModeUrl']); ?>"
          data-label-unread="<?php p($l->t('Saved · Unread only')); ?>"
          data-label-all="<?php p($l->t('Saved · Full inbox')); ?>"
          data-label-fail="<?php p($l->t('Save failed')); ?>"
          style="display:flex;flex-direction:column;gap:8px;max-width:420px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="radio"
                   name="smail-dashboard-mode"
                   value="<?php p($_['dashboardModeUnread']); ?>"
                   data-testid="smail-dashboard-mode-unread"
                   <?php if ($_['dashboardMode'] === $_['dashboardModeUnread']) { p('checked'); } ?> />
            <span><?php p($l->t('Only unread messages (default)')); ?></span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="radio"
                   name="smail-dashboard-mode"
                   value="<?php p($_['dashboardModeAll']); ?>"
                   data-testid="smail-dashboard-mode-all"
                   <?php if ($_['dashboardMode'] === $_['dashboardModeAll']) { p('checked'); } ?> />
            <span><?php p($l->t('Full inbox (latest messages)')); ?></span>
        </label>
        <span id="smail-dashboard-mode-status"
              data-testid="smail-dashboard-mode-status"
              role="status"
              aria-live="polite"
              style="font-size:12px;color:var(--color-text-maxcontrast,#888);min-height:16px;"></span>
    </form>
</div>

<div class="section"
     id="smail-app-passwords-section"
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
    <p style="margin-bottom: 12px; color: var(--color-text-maxcontrast, #888); font-size: 13px; max-width: 640px;">
        <?php p($l->t('Generate one app password per device or mail client (Thunderbird, Apple Mail, iOS, Outlook, …). Each password grants the same access as your account but can be revoked individually. Older clients without OAuth/OIDC support use these in place of your Nextcloud password.')); ?>
    </p>

    <?php if (!$_['appPasswordsAvailable']) { ?>
        <p style="padding:12px;border-radius:8px;background:var(--color-warning-hover,#fff3cd);color:var(--color-warning,#856404);font-size:13px;max-width:640px;">
            <?php p($l->t('App passwords are not available — Souvera Central and the Stalwart API URL must be configured by the administrator first.')); ?>
        </p>
    <?php } else { ?>
        <form id="smail-app-password-create-form"
              style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;max-width:640px;">
            <input type="text"
                   name="description"
                   data-testid="smail-app-password-description"
                   placeholder="<?php p($l->t('Description, e.g. Thunderbird Desktop')); ?>"
                   maxlength="120"
                   required
                   style="flex:1;min-width:240px;padding:8px 12px;border-radius:8px;border:1px solid var(--color-border,#ccc);" />
            <button type="submit"
                    data-testid="smail-app-password-create-button"
                    style="padding:8px 18px;border-radius:100px;border:0;
                           background:var(--color-primary-element,#0077C7);color:#fff;
                           font-weight:500;cursor:pointer;">
                <?php p($l->t('Create')); ?>
            </button>
        </form>

        <div id="smail-app-password-newly-created"
             data-testid="smail-app-password-newly-created"
             role="status"
             aria-live="polite"
             style="display:none;margin-bottom:16px;padding:14px;border-radius:8px;
                    background:var(--color-success-hover,#e8f5e9);border:1px solid var(--color-success,#388e3c);max-width:640px;">
        </div>

        <table id="smail-app-passwords-table"
               data-testid="smail-app-passwords-table"
               style="width:100%;max-width:640px;border-collapse:collapse;">
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
            <tbody id="smail-app-passwords-tbody"
                   data-testid="smail-app-passwords-tbody">
                <tr><td colspan="3" style="padding:12px;color:var(--color-text-maxcontrast,#888);font-size:13px;"><?php p($l->t('Loading…')); ?></td></tr>
            </tbody>
        </table>
    <?php } ?>
</div>
