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
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ComposeEditor from '../components/ComposeEditor.vue'

export default {
	name: 'ComposeView',
	components: { ComposeEditor },
	data() {
		return {
			replyEmail: null,
			forwardEmail: null,
		}
	},
	created() {
		const q = this.$route.query
		if (q.reply) {
			const parts = this.getEmailParts(q.reply)
			this.replyEmail = {
				fromAddress: parts.from || '',
				subject: parts.subject || '',
				messageId: parts.messageId || '',
			}
		} else if (q.forward) {
			const parts = this.getEmailParts(q.forward)
			this.forwardEmail = {
				fromAddress: parts.from || '',
				subject: parts.subject || '',
				messageId: parts.messageId || '',
			}
		}
	},
	methods: {
		goBack() { this.$router.replace({ name: 'inbox' }) },
		async onSent() {
			this.$router.replace({ name: 'inbox' })
		},
		getEmailParts(raw) {
			try {
				const data = JSON.parse(decodeURIComponent(raw))
				return {
					from: data.fromAddress || '',
					subject: data.subject || '',
					messageId: data.messageId || '',
				}
			} catch { return {} }
		},
	},
}
</script>
