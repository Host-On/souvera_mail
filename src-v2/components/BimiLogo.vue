<template>
	<img v-if="logoUrl" :src="logoUrl" :alt="t('souvera_mail', 'Verified sender')" class="bimi-logo__img"
		@error="onError" />
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const cache = new Map()
const pending = new Map()

export default {
	name: 'BimiLogo',
	props: {
		email: { type: String, required: true },
		size: { type: Number, default: 24 },
	},
	emits: ['loaded', 'failed'],
	data() {
		return { logoUrl: null, renderedDomain: '' }
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
	watch: {
		email() {
			this.resolve()
		},
	},
	methods: {
		resolve() {
			const domain = this.domain
			this.logoUrl = null
			if (!domain) {
				this.$emit('failed')
				return
			}
			if (pending.has(domain)) {
				pending.get(domain).then((logo) => {
					if (this.isCurrent(domain)) this.applyLogo(logo)
				})
				return
			}
			if (cache.has(domain)) {
				if (this.isCurrent(domain)) this.applyLogo(cache.get(domain))
				return
			}
			const promise = this.fetchLogo(domain)
			pending.set(domain, promise)
			promise.then((logo) => {
				pending.delete(domain)
				if (this.isCurrent(domain)) this.applyLogo(logo)
			})
		},
		async fetchLogo(domain) {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/bimi'), {
					params: { domain },
				})
				const logo = data.logoUrl || null
				cache.set(domain, logo)
				return logo
			} catch {
				cache.set(domain, null)
				return null
			}
		},
		isCurrent(domain) {
			return this.domain === domain
		},
		applyLogo(url) {
			this.renderedDomain = this.domain
			if (url) {
				this.logoUrl = url
				this.$emit('loaded', url)
			} else {
				this.logoUrl = null
				this.$emit('failed')
			}
		},
		onError() {
			if (this.renderedDomain !== this.domain) return
			cache.set(this.renderedDomain, null)
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
