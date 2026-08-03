<?php
/**
 * souvera_mail v2 — Template
 *
 * Mountpunkt für die neue Vue-3-App (souvera_mail-v2.js).
 * SnappyMail (v1) läuft parallel unter dem existierenden Template.
 */

\OCP\Util::addScript('souvera_mail', 'souvera_mail-v2');
// CSS is scoped inside the Vue bundle — no addStyle needed.

// Embed translations as JSON — consumed by main.js bootstrap.
if (!empty($translations)): ?>
<script nonce="<?php p(\OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce()); ?>">window._souvera_mail_translations = <?php echo $translations; ?>;</script>
<?php endif; ?>
<div id="souvera-mail-v2-app" style="height:100%">
	<div style="display:flex;align-items:center;justify-content:center;height:100%">
		<div style="text-align:center;color:var(--color-text-maxcontrast)">
			<div class="icon-loading" style="display:inline-block;width:32px;height:32px"></div>
			<p style="margin-top:12px;font-size:14px"><?php p($l->t('Loading Mail v2...')); ?></p>
		</div>
	</div>
</div>
