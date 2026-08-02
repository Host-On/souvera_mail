<?php
/**
 * souvera_mail v2 — Template
 *
 * Mountpunkt für die neue Vue-3-App (souvera_mail-v2.js).
 * SnappyMail (v1) läuft parallel unter dem existierenden Template.
 */

\OCP\Util::addScript('souvera_mail', 'souvera_mail-v2');
// CSS is scoped inside the Vue bundle — no addStyle needed.
?>

<div id="souvera-mail-v2-app" style="height:100%">
	<div id="app-content">
		<div class="loading-container">
			<div class="icon-loading"></div>
			<p><?php p($l->t('Loading Mail v2...')); ?></p>
		</div>
	</div>
</div>
