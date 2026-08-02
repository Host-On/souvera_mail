<template>
	<div class="compose-view">
		<ComposeEditor
			:initial-to="initialTo"
			:initial-subject="initialSubject"
			:initial-body="initialBody"
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
		return {
			initialTo: q.to || '',
			initialSubject: q.subject || '',
			initialBody: q.body || '',
		}
	},
	methods: {
		goBack() { this.$router.replace('/') },
		onSent() {
			this.$router.replace('/')
		},
	},
}
</script>
