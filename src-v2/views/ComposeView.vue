<template>
	<div class="compose-view">
		<ComposeEditor
			:reply-to="replyEmail"
			:forward-of="forwardEmail"
			:mode="composeMode"
			:initial-ext-identity="initialExtIdentity"
			:original-email="originalEmail"
			@cancel="goBack"
			@sent="onSent" />
	</div>
</template>

<script>
import ComposeEditor from '../components/ComposeEditor.vue'
import { useJmapClient } from '../composables/useJmapClient.js'

const { fetchEmailBody } = useJmapClient()

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

		return {
			replyEmail: reply,
			forwardEmail: forward,
			composeMode: q.mode || (reply ? 'reply' : (forward ? 'forward' : 'new')),
			originalEmail: null,
			initialExtIdentity: q.ext ? 'ext:' + q.ext : null,
		}
	},
	async mounted() {
		const id = this.$route.query.id
		const accountId = this.$route.query.accountId
		if (id) {
			try {
				const body = await fetchEmailBody(id, accountId)
				if (this.replyEmail) {
					this.originalEmail = { ...this.replyEmail, ...body }
				} else if (this.forwardEmail) {
					this.originalEmail = { ...this.forwardEmail, ...body }
				} else {
					this.originalEmail = { id, ...body }
				}
			} catch (e) {
				console.error('Failed to load original email', e)
			}
		}
	},
	methods: {
		goBack() { this.$router.replace({ name: 'inbox' }) },
		onSent() { this.$router.replace({ name: 'inbox' }) },
	},
}
</script>
