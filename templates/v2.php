<?php
/**
 * souvera_mail v2 — Template
 *
 * Mountpunkt für die Vue-3-App (souvera_mail-v2.js).
 */

// Translations are loaded via external souvera_mail-l10n.js (registered
// in renderV2() BEFORE souvera_mail-v2.js, so window._souvera_mail_translations
// is always set when the Vue app boots).
?>
<div id="souvera-mail-v2-app" style="width:100%;height:100%;background:var(--color-main-background)">
	<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%">
		<div style="text-align:center;color:var(--color-text-maxcontrast)">
			<div class="icon-loading" style="display:inline-block;width:32px;height:32px"></div>
			<p style="margin-top:12px;font-size:14px"><?php p($l->t('Loading Mail v2...')); ?></p>
		</div>
	</div>
</div>
