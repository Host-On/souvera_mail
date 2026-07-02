<?php

declare(strict_types=1);

/**
 * Souvera Mail — read-only admin status panel.
 *
 * Every value shown here is informational only. Configuration changes go
 * through `occ souvera_mail:bootstrap` / `occ souvera_mail:setup` / `occ souvera_mail:oidc:register-client`
 * — there are no write endpoints reachable from this template.
 *
 * @var array<string, mixed> $_
 * @var \OCP\IL10N $l
 */

$status = $_['status'] ?? [];
$issues = $status['issues'] ?? [];
$oidc = $status['oidc_provider'] ?? [];
$domain = $status['domain'] ?? [];
$engine = $status['engine'] ?? [];

\OCP\Util::addStyle('souvera_mail', 'embed');
?>
<div id="souvera_mail-admin-status" class="section">
    <h2 style="display:flex;align-items:center;gap:0.5em;">
        <img src="<?php p(image_path('souvera_mail', 'app.svg')); ?>"
             alt="Souvera Mail" style="height:32px;width:32px;">
        <?php p($l->t('Souvera Mail — Status')); ?>
    </h2>

    <p style="color:var(--color-text-maxcontrast);max-width:50em;">
        <?php p($l->t(
            'Souvera Mail is configured exclusively through occ. This panel shows the current state '
            . '— it has no write controls. See the command list below or run `occ souvera_mail:status --json` '
            . 'for a machine-readable report.'
        )); ?>
    </p>

    <?php if (!empty($issues)) : ?>
        <div class="souvera_mail-status-section" style="border-left:4px solid var(--color-warning);padding:0.5em 1em;margin:1em 0;background:var(--color-background-hover);">
            <h3><?php p($l->t('Issues to resolve')); ?></h3>
            <ul style="margin:0;padding-left:1.5em;">
                <?php foreach ($issues as $issue) : ?>
                    <li><?php p($issue); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else : ?>
        <div class="souvera_mail-status-section" style="border-left:4px solid var(--color-success);padding:0.5em 1em;margin:1em 0;background:var(--color-background-hover);">
            <strong>✓ <?php p($l->t('All checks passed — Souvera Mail is ready.')); ?></strong>
        </div>
    <?php endif; ?>

    <h3><?php p($l->t('OIDC Provider (Nextcloud → H2CK/oidc)')); ?></h3>
    <table class="grid">
        <tr>
            <th><?php p($l->t('H2CK/oidc app')); ?></th>
            <td>
                <?php if (!empty($oidc['h2ck_oidc_enabled'])) : ?>
                    <span style="color:var(--color-success);">✓ <?php p($l->t('installed and enabled')); ?></span>
                <?php else : ?>
                    <span style="color:var(--color-error);">✗ <?php p($l->t('not enabled — run')); ?> <code>occ app:install oidc && occ app:enable oidc</code></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><?php p($l->t('Smail OIDC client')); ?></th>
            <td>
                <?php if (!empty($oidc['client_registered'])) : ?>
                    <code><?php p($oidc['client_name'] ?? ''); ?></code> — <?php p($l->t('registered')); ?>
                <?php else : ?>
                    <span style="color:var(--color-error);">✗ <?php p($l->t('not registered — run')); ?> <code>occ souvera_mail:oidc:register-client</code></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><?php p($l->t('Access-token type')); ?></th>
            <td><code><?php p($oidc['default_token_type'] ?? '?'); ?></code> <?php if (($oidc['default_token_type'] ?? '') !== 'jwt') : ?> — <?php p($l->t('expected: jwt')); ?> <?php endif; ?></td>
        </tr>
        <tr>
            <th><?php p($l->t('Discovery URL')); ?></th>
            <td><a href="<?php p($oidc['discovery_url'] ?? '#'); ?>" target="_blank"><?php p($oidc['discovery_url'] ?? ''); ?></a></td>
        </tr>
        <tr>
            <th><?php p($l->t('JWKS URL (for mail server)')); ?></th>
            <td><a href="<?php p($oidc['jwks_url'] ?? '#'); ?>" target="_blank"><?php p($oidc['jwks_url'] ?? ''); ?></a></td>
        </tr>
    </table>

    <h3><?php p($l->t('Mail Domain Profile')); ?></h3>
    <?php $configuredDomains = $domain['configured'] ?? []; ?>
    <?php if ($configuredDomains === []) : ?>
        <p><em><?php p($l->t('No domain configured. Run')); ?> <code>occ souvera_mail:setup --imap-host … --domain …</code>.</em></p>
    <?php else : ?>
        <?php foreach ($configuredDomains as $domainName => $cfg) : ?>
            <table class="grid">
                <tr><th><?php p($l->t('Domain')); ?></th><td><code><?php p($domainName); ?></code></td></tr>
                <?php if (isset($cfg['imap'])) : ?>
                <tr><th>IMAP</th><td><code><?php p($cfg['imap']['host'] . ':' . $cfg['imap']['port'] . ' (' . $cfg['imap']['ssl'] . ')'); ?></code></td></tr>
                <tr><th>SMTP</th><td><code><?php p($cfg['smtp']['host'] . ':' . $cfg['smtp']['port'] . ' (' . $cfg['smtp']['ssl'] . ')'); ?></code></td></tr>
                <tr><th>Sieve</th><td><?php p($cfg['sieve_enabled'] ? $l->t('enabled') : $l->t('disabled')); ?></td></tr>
                <?php endif; ?>
            </table>
        <?php endforeach; ?>
        <p><strong><?php p($l->t('OIDC audience hint:')); ?></strong> <code><?php p($domain['oidc_audience'] ?? ''); ?></code></p>
    <?php endif; ?>

    <h3><?php p($l->t('Engine')); ?></h3>
    <table class="grid">
        <?php foreach ($engine as $key => $value) : ?>
            <tr>
                <th><?php p($key); ?></th>
                <td><code><?php p(\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value); ?></code></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h3><?php p($l->t('Configuration commands')); ?></h3>
    <p><?php p($l->t('All configuration is performed through occ:')); ?></p>
    <pre style="background:var(--color-background-dark);padding:1em;border-radius:6px;overflow:auto;">
# One-shot install (idempotent)
occ souvera_mail:bootstrap \
    --mail-imap-host  mail.example.com --mail-imap-port  993 --mail-imap-ssl  ssl \
    --mail-smtp-host  mail.example.com --mail-smtp-port  465 --mail-smtp-ssl  ssl \
    --mail-sieve-host mail.example.com --mail-sieve-port 4190 --mail-sieve-ssl ssl \
    --domain          example.com \
    --client-secret-out /etc/souvera_mail/oidc-client-secret \
    --json

# Individual operations
occ souvera_mail:oidc:register-client --json
occ souvera_mail:setup     --imap-host … --domain … --json
occ souvera_mail:status    --json
occ souvera_mail:reset     --purge-oidc-client --json

# Health-check (returns non-zero on any blocker)
occ souvera_mail:status --json | jq .status</pre>

    <p style="margin-top:2em;color:var(--color-text-maxcontrast);">
        <small><?php p($l->t('Souvera Mail version:')); ?> <?php p($status['app']['version'] ?? '?'); ?></small>
    </p>
</div>
