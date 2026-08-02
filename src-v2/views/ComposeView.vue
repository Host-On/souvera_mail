<template>
	<div class="compose-view">
		<ComposeEditor
			:reply-to="replyEmail"
			:forward-of="forwardEmail"
			@cancel="goBack"
			@sent="onSent" />
	</div>
</template>

<script>
import ComposeEditor from '../components/ComposeEditor.vue'

export default {
	name: 'ComposeView',
	components: { ComposeEditor },
	data() {
		const q = this.$route.query
		let reply = null, forward = null
		try {
			if (q.reply) reply = JSON.parse(q.reply)
			if (q.forward) forward = JSON.parse(q.forward)
		} catch {}
		return { replyEmail: reply, forwardEmail: forward }
	},
	methods: {
		goBack() { this.$router.replace({ name: 'inbox' }) },
		onSent() { this.$router.replace({ name: 'inbox' }) },
	},
}
</script>
