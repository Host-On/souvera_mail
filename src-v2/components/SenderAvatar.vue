<template>
	<span class="sender-avatar">
		<BimiLogo v-if="email" class="sender-avatar__bimi" :email="email" :size="size"
			@loaded="onBimiLoaded" @failed="onBimiFailed" />
		<!-- Gravatar unsichtbar vorladen: erst bei erfolgreichem load wird das
		     Bild sichtbar, bei 404 bleiben die Initialen stabil (kein Flackern). -->
		<img v-if="showGravatarCandidate" class="sender-avatar__preload" :src="gravatarUrl"
			alt="" @load="onGravatarLoad" @error="onGravatarFail" />
		<img v-if="showGravatar" class="sender-avatar__img" :src="gravatarUrl"
			alt="" @error="onGravatarFail" />
		<NcAvatar v-if="showInitials" :display-name="alt" :size="size" />
	</span>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import BimiLogo from './BimiLogo.vue'
import { md5 } from '../utils/md5.js'

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
			bimiUrl: null,
			bimiResolved: false,
			gravatarFailed: false,
			gravatarLoaded: false,
		}
	},
	computed: {
		alt() { return this.name || this.email || '?' },
		gravatarUrl() {
			const clean = this.email.trim().toLowerCase()
			if (!clean || !clean.includes('@')) return ''
			return `https://www.gravatar.com/avatar/${md5(clean)}?d=404&s=${this.size * 2}`
		},
		showBimi() { return !!this.bimiUrl },
		showGravatarCandidate() { return this.bimiResolved && !this.bimiUrl && !!this.gravatarUrl && !this.gravatarFailed },
		showGravatar() { return this.showGravatarCandidate && this.gravatarLoaded },
		showInitials() { return !this.showBimi && !this.showGravatar },
	},
	watch: {
		email() {
			this.bimiUrl = null
			this.bimiResolved = false
			this.gravatarFailed = false
			this.gravatarLoaded = false
		},
	},
	methods: {
		onBimiLoaded(url) {
			this.bimiUrl = url
			this.bimiResolved = true
			this.$emit('loaded')
		},
		onBimiFailed() {
			this.bimiUrl = null
			this.bimiResolved = true
		},
		onGravatarLoad() {
			this.gravatarLoaded = true
		},
		onGravatarFail() {
			this.gravatarFailed = true
		},
	},
}
</script>

<style scoped>
.sender-avatar { display: inline-flex; align-items: center; flex-shrink: 0; }
.sender-avatar__preload { display: none; }
.sender-avatar__img {
	width: v-bind(size + 'px'); height: v-bind(size + 'px');
	border-radius: 50%; object-fit: cover;
}
</style>
