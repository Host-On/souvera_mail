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
