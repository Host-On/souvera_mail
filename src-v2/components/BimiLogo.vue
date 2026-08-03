<template>
	<span v-if="logoUrl" class="bimi-logo">
		<img :src="logoUrl" :alt="t('souvera_mail', 'Verified sender')" class="bimi-logo__img"
			@error="onError" />
	</span>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const cache = new Map()

export default {
	name: 'BimiLogo',
	props: {
		email: { type: String, required: true },
	},
	emits: ['loaded'],
	data() {
		return { logoUrl: null }
	},
	mounted() {
		this.resolve()
	},
	methods: {
		async resolve() {
			if (!this.email) return
			const domain = this.email.split('@')[1]
			if (!domain) return
			if (cache.has(domain)) {
				this.logoUrl = cache.get(domain)
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/bimi'), {
					params: { domain },
				})
				cache.set(domain, data.logoUrl)
				this.logoUrl = data.logoUrl
				if (data.logoUrl) this.$emit('loaded')
			} catch {}
		},
		onError() {
			cache.set(this.email.split('@')[1], null)
			this.logoUrl = null
		},
	},
}
</script>

<style scoped>
.bimi-logo { display: inline-flex; align-items: center; margin-right: 8px; flex-shrink: 0; }
.bimi-logo__img {
	width: 24px; height: 24px; border-radius: 4px;
	object-fit: contain; background: #fff;
	border: 1px solid var(--color-border);
}
</style>
