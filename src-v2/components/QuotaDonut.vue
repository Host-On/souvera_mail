<template>
	<div v-if="total > 0 || unlimited" class="quota-donut"
		:class="{ 'quota-donut--inline': inline }">
		<svg viewBox="0 0 36 36" class="quota-donut__svg"
			:style="{ width: size + 'px', height: size + 'px' }">
			<circle cx="18" cy="18" r="15.5" fill="none"
				:stroke="trackColor" stroke-width="4" />
			<circle cx="18" cy="18" r="15.5" fill="none"
				:stroke="donutColor"
				stroke-width="4"
				stroke-linecap="round"
				:stroke-dasharray="dashArray"
				:stroke-dashoffset="25"
				transform="rotate(-90 18 18)"
				class="quota-donut__arc" />
		</svg>
		<div class="quota-donut__label">{{ label }}</div>
	</div>
</template>

<script>
export default {
	name: 'QuotaDonut',
	props: {
		used: { type: Number, default: 0 },
		total: { type: Number, default: 1 },
		unlimited: { type: Boolean, default: false },
		size: { type: Number, default: 48 },
		// inline: donut acts as the icon of a nav-style row — icon left,
		// label right, left-aligned, single-line height (nav footer use).
		inline: { type: Boolean, default: false },
		// lightTrack: white track circle for dark/grey backgrounds
		// (nav footer); default uses the theme border colour (settings).
		lightTrack: { type: Boolean, default: false },
	},
	computed: {
		trackColor() {
			return this.lightTrack ? '#ffffff' : 'var(--color-border)'
		},
		label() {
			if (this.unlimited) return this.inline ? (this.t ? this.t('souvera_mail', 'Unlimited') : 'Unlimited') : '∞'
			return this.percent + '%'
		},
		percent() {
			return Math.round((this.used / this.total) * 100)
		},
		dashArray() {
			const circum = 2 * Math.PI * 15.5
			const filled = circum * (this.percent / 100)
			return `${filled} ${circum - filled}`
		},
		donutColor() {
			// Souvera brand blue (#0082c9) — the fill is always brand-coloured
			// regardless of the Nextcloud theme.
			return '#0082c9'
		},
	},
}
</script>

<style scoped>
.quota-donut { padding: 6px 8px; text-align: center; }
.quota-donut__svg { display: block; margin: 0 auto; }
.quota-donut__arc { transition: stroke-dasharray 0.5s ease; }
.quota-donut__label { font-size: 11px; color: var(--color-text-maxcontrast); margin-top: 4px; }

.quota-donut--inline {
	display: flex; align-items: center; gap: 10px;
	padding: 6px 12px 6px 8px; text-align: left;
}
.quota-donut--inline .quota-donut__svg { margin: 0; flex-shrink: 0; }
.quota-donut--inline .quota-donut__label {
	font-size: 13px; margin-top: 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
</style>
