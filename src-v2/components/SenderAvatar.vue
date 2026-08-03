<template>
	<span class="sender-avatar">
		<BimiLogo class="sender-avatar__bimi" :email="email" @loaded="onBimiLoaded" />
		<img v-if="showGravatar && !hasBimi" class="sender-avatar__img" :src="gravatarUrl" @error="onGravatarFail" />
		<NcAvatar v-if="!hasBimi && !showGravatar" :display-name="alt" :size="size" />
	</span>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import BimiLogo from './BimiLogo.vue'

export default {
	name: 'SenderAvatar',
	components: { NcAvatar, BimiLogo },
	props: {
		email: { type: String, default: '' },
		name: { type: String, default: '' },
		size: { type: Number, default: 40 },
	},
	emits: ['loaded'],
	data() {
		return {
			hasBimi: false,
			bimiChecked: false,
			showGravatar: false,
			gravatarFailed: false,
		}
	},
	computed: {
		alt() { return this.name || this.email || '?' },
		gravatarUrl() {
			const clean = this.email.trim().toLowerCase()
			// Simple MD5-like hash (not crypto-safe, fine for Gravatar)
			let hash = ''
			const str = clean
			for (let i = 0; i < str.length; i++) {
				hash += str.charCodeAt(i).toString(16)
			}
			return `https://www.gravatar.com/avatar/${this.simpleHash(clean)}?d=404&s=${this.size * 2}`
		},
	},
	mounted() {
		// After 3s, if BIMI didn't load, try Gravatar
		setTimeout(() => {
			if (!this.hasBimi) {
				this.bimiChecked = true
				this.showGravatar = true
			}
		}, 3000)
	},
	methods: {
		simpleHash(str) {
			let h = 0
			for (let i = 0; i < str.length; i++) {
				h = ((h << 5) - h) + str.charCodeAt(i)
				h |= 0
			}
			return Math.abs(h).toString(16).padStart(8, '0')
		},
		onBimiLoaded() {
			this.hasBimi = true
			this.$emit('loaded')
		},
		onGravatarFail() {
			this.gravatarFailed = true
			this.showGravatar = false
		},
	},
}
</script>

<style scoped>
.sender-avatar { display: inline-flex; align-items: center; flex-shrink: 0; }
.sender-avatar__img {
	border-radius: 50%; object-fit: cover;
}
</style>
