<template>
	<div class="email-list-skeleton">
		<div v-for="i in count" :key="i" class="email-list-skeleton__row">
			<div class="skeleton-avatar" />
			<div class="skeleton-lines">
				<!-- 3 Zeilen wie die echten Items (Sender, Betreff, Vorschau)
				     — kein vertikaler Layout-Sprung beim Übergang. -->
				<div class="skeleton-line" :style="{ width: widths[i % widths.length] }" />
				<div class="skeleton-line skeleton-line--subject" />
				<div class="skeleton-line skeleton-line--preview" :style="{ width: previews[i % previews.length] }" />
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
			previews: ['62%', '48%', '70%', '55%', '66%', '52%', '60%', '68%'],
		}
	},
}
</script>

<style scoped>
.email-list-skeleton__row { display: flex; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--color-border); align-items: flex-start; }
.skeleton-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(90deg, var(--color-background-hover) 25%, var(--color-background-dark) 50%, var(--color-background-hover) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; flex-shrink: 0; }
.skeleton-lines { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.skeleton-line { height: 12px; border-radius: 4px; background: linear-gradient(90deg, var(--color-background-hover) 25%, var(--color-background-dark) 50%, var(--color-background-hover) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; }
.skeleton-line--subject { width: 70% !important; }
.skeleton-line--preview { height: 10px; opacity: 0.7; }
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
</style>
