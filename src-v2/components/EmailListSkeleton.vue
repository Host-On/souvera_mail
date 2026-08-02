<template>
	<div class="email-list-skeleton">
		<div v-for="i in count" :key="i" class="email-list-skeleton__row">
			<div class="skeleton-avatar" />
			<div class="skeleton-lines">
				<div class="skeleton-line" :style="{ width: widths[i % widths.length] }" />
				<div class="skeleton-line skeleton-line--short" />
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'EmailListSkeleton',
	props: { count: { type: Number, default: 8 } },
	data() {
		return {
			widths: ['40%', '55%', '35%', '60%', '45%', '50%', '33%', '58%'],
		}
	},
}
</script>

<style scoped>
.email-list-skeleton__row { display: flex; gap: 10px; padding: 10px 12px; border-bottom: 1px solid var(--color-border); align-items: center; }
.skeleton-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(90deg, var(--color-background-hover) 25%, var(--color-background-dark) 50%, var(--color-background-hover) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; }
.skeleton-lines { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.skeleton-line { height: 12px; border-radius: 4px; background: linear-gradient(90deg, var(--color-background-hover) 25%, var(--color-background-dark) 50%, var(--color-background-hover) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; }
.skeleton-line--short { width: 70% !important; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
</style>
