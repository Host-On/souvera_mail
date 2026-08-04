<template>
	<img v-if="logoUrl" :src="logoUrl" :alt="t('souvera_mail', 'Verified sender')" class="bimi-logo__img"
		@error="onError" />
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const cache = new Map()

export default {
	name: 'BimiLogo',
	props: {
		email: { type: String, required: true },
		size: { type: Number, default: 24 },
	},
	emits: ['loaded', 'failed'],
	data() {
		return { logoUrl: null }
	},
	computed: {
		domain() {
			if (!this.email) return ''
			const parts = this.email.split('@')
			return parts.length === 2 ? parts[1] : ''
		},
	},
	mounted() {
		this.resolve()
	},
	methods: {
		async resolve() {
			if (!this.domain) {
				this.$emit('failed')
				return
			}
			if (cache.has(this.domain)) {
				this.applyLogo(cache.get(this.domain))
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/bimi'), {
					params: { domain: this.domain },
				})
				cache.set(this.domain, data.logoUrl || null)
				this.applyLogo(data.logoUrl || null)
			} catch {
				this.applyLogo(null)
			}
		},
		applyLogo(url) {
			if (url) {
				this.logoUrl = url
				this.$emit('loaded', url)
			} else {
				this.logoUrl = null
				this.$emit('failed')
			}
		},
		onError() {
			cache.set(this.domain, null)
			this.logoUrl = null
			this.$emit('failed')
		},
	},
}
</script>

<style scoped>
.bimi-logo__img {
	width: v-bind(size + 'px'); height: v-bind(size + 'px');
	border-radius: 50%; object-fit: cover;
	background: #fff; border: 1px solid var(--color-border);
	display: inline-flex; flex-shrink: 0;
}
</style>
