<template>
	<div class="souvera-welcome" data-testid="wizard-screen-welcome">
		<div class="souvera-welcome__hero">
			<EmailArrowRight :size="56" class="souvera-welcome__hero-icon" />
			<h1 class="souvera-welcome__title">{{ t('souvera_mail', 'Willkommen bei Souvera Mail') }}</h1>
			<p class="souvera-welcome__lead">
				{{ t('souvera_mail', 'Wir können deine Mails vom alten Anbieter automatisch übertragen — sicher, im Hintergrund und ohne dass du irgendwo Software installieren musst.') }}
			</p>
		</div>

		<ul class="souvera-welcome__usp">
			<li>
				<CheckCircleOutline :size="18" />
				<span>{{ t('souvera_mail', 'Ordnerstruktur bleibt exakt erhalten.') }}</span>
			</li>
			<li>
				<CheckCircleOutline :size="18" />
				<span>{{ t('souvera_mail', 'Anhänge, Kalendereinladungen und Flags werden mitgenommen.') }}</span>
			</li>
			<li>
				<CheckCircleOutline :size="18" />
				<span>{{ t('souvera_mail', 'Läuft im Hintergrund. Du kannst schon Mails schreiben, während der Import läuft.') }}</span>
			</li>
		</ul>

		<div class="souvera-actions souvera-actions--split">
			<NcButton
				type="tertiary"
				data-testid="wizard-welcome-dismiss"
				@click="$emit('dismiss-forever')">
				{{ t('souvera_mail', 'Nicht mehr zeigen') }}
			</NcButton>
			<div class="souvera-actions__right">
				<NcButton
					type="secondary"
					data-testid="wizard-welcome-later"
					@click="$emit('close')">
					{{ t('souvera_mail', 'Später') }}
				</NcButton>
				<NcButton
					type="primary"
					data-testid="wizard-welcome-start"
					@click="$emit('advance')">
					<template #icon>
						<ArrowRight :size="20" />
					</template>
					{{ t('souvera_mail', 'Jetzt starten') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import EmailArrowRight from 'vue-material-design-icons/EmailArrowRight.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'

export default {
	name: 'WelcomeScreen',
	components: { NcButton, EmailArrowRight, CheckCircleOutline, ArrowRight },
	emits: ['advance', 'close', 'dismiss-forever'],
}
</script>

<style scoped>
.souvera-welcome {
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-welcome__hero {
	text-align: center;
	padding: 12px 0 4px;
}
.souvera-welcome__hero-icon {
	color: var(--color-primary-element);
	margin-bottom: 8px;
}
.souvera-welcome__title {
	font-size: 1.5rem;
	font-weight: var(--font-weight-heading, 700);
	margin: 0 0 8px;
	line-height: 1.25;
	letter-spacing: -0.01em;
	color: var(--color-main-text);
}
.souvera-welcome__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 460px;
	margin-inline: auto;
	line-height: 1.55;
}
.souvera-welcome__usp {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.souvera-welcome__usp li {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-main-text);
}
.souvera-welcome__usp li .material-design-icon {
	color: var(--color-success);
	flex-shrink: 0;
}
.souvera-actions__right {
	display: flex;
	gap: 8px;
}
</style>
