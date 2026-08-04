<template>
	<div v-if="total > 0 || unlimited" class="quota-donut">
		<svg viewBox="0 0 36 36" class="quota-donut__svg"
			:style="{ width: size + 'px', height: size + 'px' }">
			<circle cx="18" cy="18" r="15.5" fill="none"
				stroke="var(--color-border)" stroke-width="4" />
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
	},
	computed: {
		label() {
			return this.unlimited ? '∞' : this.percent + '%'
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
			if (this.percent > 90) return 'var(--color-error)'
			if (this.percent > 70) return 'var(--color-warning)'
			return 'var(--color-success)'
		},
	},
}
</script>

<style scoped>
.quota-donut { padding: 6px 8px; text-align: center; }
.quota-donut__svg { display: block; margin: 0 auto; }
.quota-donut__arc { transition: stroke-dasharray 0.5s ease; }
.quota-donut__label { font-size: 11px; color: var(--color-text-maxcontrast); margin-top: 4px; }
</style>
