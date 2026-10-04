<template>
	<NcDialog :name="dialogTitle"
		:can-close="!busy"
		@closing="$emit('close')">
		<div class="share-dialog">
			<p class="share-mailbox-name">
				<span class="share-mailbox-icon" v-html="ctxIcons.folder" />
				{{ mailbox.name }}
			</p>

			<p v-if="error" class="share-error">{{ error }}</p>

			<div class="share-new">
				<NcTextField :value.sync="q"
					:label="t('souvera_mail', 'Freigeben an Benutzer')"
					:placeholder="t('souvera_mail', 'Name oder Benutzername…')"
					@input="onSearch" />
				<ul v-if="userResults.length > 0" class="share-user-list">
					<li v-for="u in userResults" :key="u.uid" @click="pickUser(u)">
						<span class="share-user-name">{{ u.displayName }}</span>
						<span class="share-user-detail">{{ u.email || u.uid }}</span>
					</li>
				</ul>

				<template v-if="pickedUser">
					<p class="share-picked">
						{{ t('souvera_mail', 'Freigeben an') }}: <strong>{{ pickedUser.displayName }}</strong>
						<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Auswahl entfernen')" @click="clearPicked">
							<template #icon><span class="share-x">×</span></template>
						</NcButton>
					</p>
					<div class="share-permissions">
						<label class="share-radio">
							<input v-model="permission" type="radio" value="read">
							{{ t('souvera_mail', 'Lesen') }}
						</label>
						<label class="share-radio">
							<input v-model="permission" type="radio" value="write">
							{{ t('souvera_mail', 'Bearbeiten (lesen, verschieben, markieren)') }}
						</label>
					</div>
					<label class="share-children">
						<input v-model="includeChildren" type="checkbox">
						{{ t('souvera_mail', 'Unterordner einbeziehen') }}
					</label>
					<NcButton variant="primary" :disabled="busy" @click="grant">
						{{ t('souvera_mail', 'Freigeben') }}
					</NcButton>
				</template>
			</div>

			<div v-if="grants.length > 0" class="share-existing">
				<h4>{{ t('souvera_mail', 'Geteilt mit') }}</h4>
				<ul>
					<li v-for="g in grants" :key="g.accountId">
						<span class="share-user-name">{{ g.email || g.accountId }}</span>
						<span class="share-user-detail">{{ rightsLabel(g) }}</span>
						<NcButton variant="tertiary" :disabled="busy" @click="revoke(g)">
							{{ t('souvera_mail', 'Entziehen') }}
						</NcButton>
					</li>
				</ul>
			</div>
			<p v-else-if="!loading" class="share-empty">
				{{ t('souvera_mail', 'Dieser Ordner ist bisher nicht freigegeben.') }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcDialog, NcButton, NcTextField } from '@nextcloud/vue'
import { CTX_ICONS } from '../utils/contextMenuIcons.js'

export default {
	name: 'ShareDialog',
	components: { NcDialog, NcButton, NcTextField },
	props: {
		mailbox: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return {
			ctxIcons: CTX_ICONS,
			grants: [],
			loading: true,
			busy: false,
			error: '',
			q: '',
			userResults: [],
			pickedUser: null,
			permission: 'read',
			includeChildren: true,
			searchTimer: null,
		}
	},
	computed: {
		dialogTitle() {
			return this.t('souvera_mail', 'Ordner freigeben')
		},
	},
	async mounted() {
		await this.loadGrants()
	},
	beforeDestroy() {
		if (this.searchTimer) clearTimeout(this.searchTimer)
	},
	methods: {
		async loadGrants() {
			this.loading = true
			this.error = ''
			try {
				const r = await axios.get(generateUrl('/apps/souvera_mail/api/v2/share'), {
					params: { mailboxId: this.mailbox.id },
				})
				const d = r.data.ocs?.data || r.data
				this.grants = d.grants || []
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('souvera_mail', 'Freigabe-Status konnte nicht geladen werden')
			} finally {
				this.loading = false
			}
		},
		onSearch() {
			if (this.searchTimer) clearTimeout(this.searchTimer)
			const q = this.q.trim()
			if (q.length < 2) { this.userResults = []; return }
			this.searchTimer = setTimeout(async () => {
				try {
					const r = await axios.get(generateUrl('/apps/souvera_mail/api/v2/share/users'), { params: { q } })
					const d = r.data.ocs?.data || r.data
					this.userResults = (d.users || []).filter((u) => u.uid !== this.currentUid)
				} catch (e) {
					this.userResults = []
				}
			}, 250)
		},
		pickUser(u) {
			this.pickedUser = u
			this.userResults = []
			this.q = ''
		},
		clearPicked() {
			this.pickedUser = null
		},
		async grant() {
			if (!this.pickedUser || this.busy) return
			this.busy = true
			this.error = ''
			try {
				await axios.post(generateUrl('/apps/souvera_mail/api/v2/share'), {
					mailboxId: this.mailbox.id,
					granteeUid: this.pickedUser.uid,
					permission: this.permission,
					includeChildren: this.includeChildren,
				})
				this.pickedUser = null
				await this.loadGrants()
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('souvera_mail', 'Freigabe fehlgeschlagen')
			} finally {
				this.busy = false
			}
		},
		async revoke(g) {
			if (this.busy) return
			this.busy = true
			this.error = ''
			try {
				const params = { mailboxId: this.mailbox.id }
				if (g.granteeUid) {
					params.granteeUid = g.granteeUid
				} else {
					params.granteeAccountId = g.accountId
				}
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/share'), { params })
				await this.loadGrants()
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('souvera_mail', 'Entziehen fehlgeschlagen')
			} finally {
				this.busy = false
			}
		},
		rightsLabel(g) {
			const r = g.rights || {}
			if (r.mayAddItems || r.mayRemoveItems) {
				return this.t('souvera_mail', 'Bearbeiten')
			}
			return this.t('souvera_mail', 'Lesen')
		},
	},
}
</script>

<style scoped>
.share-dialog {
	min-width: 380px;
	max-width: 460px;
}
.share-mailbox-name {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	margin: 0 0 12px;
}
.share-mailbox-icon :deep(svg) {
	width: 16px;
	height: 16px;
}
.share-error {
	color: var(--color-error-text);
	margin: 8px 0;
}
.share-new {
	position: relative;
	margin-bottom: 12px;
}
.share-user-list {
	position: absolute;
	z-index: 10;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
	max-height: 200px;
	overflow-y: auto;
	list-style: none;
	margin: 4px 0 0;
	padding: 4px 0;
}
.share-user-list li {
	display: flex;
	flex-direction: column;
	padding: 6px 12px;
	cursor: pointer;
}
.share-user-list li:hover {
	background: var(--color-background-hover);
}
.share-user-name {
	font-weight: 500;
}
.share-user-detail {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
.share-picked {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 10px 0 6px;
}
.share-x {
	font-size: 16px;
	line-height: 1;
}
.share-permissions {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;
}
.share-radio,
.share-children {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
}
.share-children {
	margin: 4px 0 10px;
}
.share-existing h4 {
	margin: 14px 0 6px;
	font-weight: 600;
}
.share-existing ul {
	list-style: none;
	margin: 0;
	padding: 0;
}
.share-existing li {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}
.share-existing li:last-child {
	border-bottom: none;
}
.share-existing .share-user-detail {
	flex: 1;
}
.share-empty {
	color: var(--color-text-maxcontrast);
}
</style>
