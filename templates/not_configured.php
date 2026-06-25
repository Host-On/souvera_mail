<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('souvera_mail', 'embed');
?>
<style>
#souvera-mail-bootstrap-hint {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    text-align: left;
    padding: 2em;
    color: var(--color-main-text, #fff);
}
#souvera-mail-bootstrap-hint .panel {
    max-width: 720px;
    background: var(--color-main-background, #181818);
    border: 1px solid var(--color-border, #333);
    border-radius: 16px;
    padding: 32px 36px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
}
#souvera-mail-bootstrap-hint h2 {
    margin: 0 0 8px;
    font-size: 28px;
    letter-spacing: -0.02em;
}
#souvera-mail-bootstrap-hint .lead {
    margin: 0 0 24px;
    color: var(--color-text-maxcontrast, #aaa);
    font-size: 15px;
    line-height: 1.5;
}
#souvera-mail-bootstrap-hint pre {
    margin: 0 0 16px;
    padding: 16px 18px;
    background: var(--color-background-dark, #0e0e0e);
    border: 1px solid var(--color-border, #2a2a2a);
    border-radius: 10px;
    overflow-x: auto;
    font: 13px/1.55 ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    color: #d4d4d4;
}
#souvera-mail-bootstrap-hint .small {
    margin: 16px 0 0;
    color: var(--color-text-maxcontrast, #888);
    font-size: 13px;
    line-height: 1.55;
}
#souvera-mail-bootstrap-hint code.inline {
    padding: 2px 6px;
    border-radius: 6px;
    background: var(--color-background-dark, #0e0e0e);
    border: 1px solid var(--color-border, #2a2a2a);
    font: 12px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
<div id="souvera_mail-bootstrap-hint">
    <div class="panel">
        <h2><?php p($l->t('Souvera Mail')); ?></h2>

        <?php if ($_['isAdmin']) { ?>
            <p class="lead">
                <?php p($l->t('This Nextcloud instance has Souvera Mail installed, but no mail domain is configured yet. Souvera Mail is a CLI-managed application: there is no browser-based setup UI. Run the following command on the Nextcloud host to register the OIDC client with H2CK/oidc, persist the mail domain, and finalise the bootstrap:')); ?>
            </p>

<pre data-testid="souvera-mail-bootstrap-snippet">sudo -u www-data php occ souvera_mail:bootstrap \
    --domain example.com \
    --mail-imap-host imap.example.com \
    --mail-smtp-host smtp.example.com \
    --client-secret-out /etc/souvera_mail/oidc.secret \
    --json</pre>

            <p class="small">
                <?php p($l->t('Once bootstrap completes, single sign-on via H2CK/oidc takes over automatically — no per-user configuration is required. To inspect the current setup at any time run')); ?>
                <code class="inline">occ souvera_mail:status --json</code>.
                <?php p($l->t('To start over')); ?>
                <code class="inline">occ souvera_mail:reset</code>.
            </p>

            <p class="small">
                <?php p($l->t('Prerequisites for the command to succeed:')); ?>
                <code class="inline">occ app:enable oidc</code>
                <?php p($l->t('(H2CK/oidc 1.17+ must be enabled and have at least one signing key) and the souvera_central app must already have provisioned the user\'s mailbox on Stalwart.')); ?>
            </p>

        <?php } else { ?>
            <p class="lead">
                <?php p($l->t('Souvera Mail is not configured yet. Please contact your administrator to run')); ?>
                <code class="inline">occ souvera_mail:bootstrap</code>.
            </p>
        <?php } ?>
    </div>
</div>
