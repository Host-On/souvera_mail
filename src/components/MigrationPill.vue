<template>
	<button
		type="button"
		class="souvera-migration-pill"
		:class="stateClass"
		:data-state="state"
		:aria-label="label"
		data-testid="migration-pill-button"
		@click="$emit('click')">
		<span class="souvera-migration-pill__icon" aria-hidden="true">
			<EmailArrowRight :size="18" />
		</span>
		<span class="souvera-migration-pill__text">{{ label }}</span>
	</button>
</template>

<script>
import EmailArrowRight from 'vue-material-design-icons/EmailArrowRight.vue'

export default {
	name: 'MigrationPill',
	components: { EmailArrowRight },
	props: {
		label: { type: String, required: true },
		state: {
			type: String,
			default: 'idle',
			validator: v => ['idle', 'running', 'done', 'fail'].includes(v),
		},
	},
	emits: ['click'],
	computed: {
		stateClass() {
			return `souvera-migration-pill--${this.state}`
		},
	},
}
</script>

<style scoped>
.souvera-migration-pill {
	position: fixed;
	z-index: 2100000000;
	right: 24px;
	bottom: 24px;
	height: var(--sc-control-height);
	padding: 0 var(--sc-control-padding-x);
	border: 0;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font: 500 14px/1 var(--font-face);
	box-shadow: 0 6px 22px rgba(0, 0, 0, 0.28), 0 2px 4px rgba(0, 0, 0, 0.18);
	cursor: pointer;
	transition: transform 120ms ease, box-shadow 120ms ease, background 200ms ease, filter 120ms ease;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	max-width: 260px;
}
.souvera-migration-pill:hover {
	transform: translateY(-1px);
	box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32);
	filter: brightness(1.05);
}
.souvera-migration-pill:focus-visible {
	outline: 0;
	box-shadow: 0 6px 22px rgba(0, 0, 0, 0.28), var(--sc-focus-ring);
}

.souvera-migration-pill--idle {
	background: var(--color-primary-element);
}
.souvera-migration-pill--running {
	background: var(--color-success);
	animation: souvera-migration-pill-pulse 2s ease-in-out infinite;
}
.souvera-migration-pill--done {
	background: var(--color-success);
}
.souvera-migration-pill--fail {
	background: var(--color-error);
}

.souvera-migration-pill__icon {
	display: inline-flex;
	align-items: center;
	line-height: 1;
}
.souvera-migration-pill__text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

@keyframes souvera-migration-pill-pulse {
	0%, 100% { box-shadow: 0 6px 22px rgba(0, 0, 0, 0.28), 0 0 0 0 rgba(var(--color-success-rgb, 70, 186, 97), 0.5); }
	50%      { box-shadow: 0 6px 22px rgba(0, 0, 0, 0.28), 0 0 0 12px rgba(var(--color-success-rgb, 70, 186, 97), 0); }
}

@media (max-width: 540px) {
	.souvera-migration-pill {
		right: 12px;
		bottom: 12px;
		padding: 0 14px;
	}
	.souvera-migration-pill__text {
		max-width: 140px;
	}
}
</style>
