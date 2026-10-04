<template>
	<NcDialog :name="dialogTitle"
		size="normal"
		:can-close="!busy"
		@closing="$emit('close')">
		<div class="share-dialog">
			<!-- Ordner-Kontext -->
			<div class="share-folder-card">
				<Folder :size="18" class="share-folder-icon" />
				<span class="share-folder-name">{{ mailbox.name }}</span>
			</div>

			<p v-if="error" class="share-error">{{ error }}</p>

			<!-- Neue Freigabe -->
			<section class="share-section">
				<h4 class="share-label">{{ t('souvera_mail', 'Freigeben an') }}</h4>
				<NcTextField v-model="q"
					:label="t('souvera_mail', 'Freigeben an Benutzer')"
					:placeholder="t('souvera_mail', 'Name oder Benutzername…')" />
				<ul v-if="userResults.length > 0" class="share-user-list">
					<li v-for="u in userResults" :key="u.uid" role="button" tabindex="0"
						@click="pickUser(u)"
						@keydown.enter.prevent="pickUser(u)"
						@keydown.space.prevent="pickUser(u)">
						<span class="share-initials">{{ initials(u.displayName || u.uid) }}</span>
						<span class="share-user-name">{{ u.displayName }}</span>
						<span class="share-user-detail">{{ u.email || u.uid }}</span>
					</li>
				</ul>

				<template v-if="pickedUser">
					<div class="share-picked-chip">
						<NcAvatar v-if="pickedUser.uid" :user="pickedUser.uid"
							:display-name="pickedUser.displayName" :size="34"
							:disable-menu="true" :show-user-status="false" />
						<span v-else class="share-initials">{{ initials(pickedUser.displayName) }}</span>
						<span class="share-picked-meta">
							<strong>{{ pickedUser.displayName }}</strong>
							<small>{{ pickedUser.email || pickedUser.uid }}</small>
						</span>
						<NcButton variant="tertiary" :aria-label="t('souvera_mail', 'Auswahl entfernen')" @click="clearPicked">
							<template #icon><Close :size="18" /></template>
						</NcButton>
					</div>

					<h4 class="share-label">{{ t('souvera_mail', 'Berechtigung') }}</h4>
					<div class="share-perm-cards">
						<label class="share-perm-card" :class="{ 'share-perm-card--active': permission === 'read' }">
							<input v-model="permission" type="radio" value="read">
							<Lock :size="16" class="share-perm-icon" />
							<span class="share-perm-text">
								<strong>{{ t('souvera_mail', 'Lesen') }}</strong>
								<small>{{ t('souvera_mail', 'Ordner ansehen und Mails lesen') }}</small>
							</span>
						</label>
						<label class="share-perm-card" :class="{ 'share-perm-card--active': permission === 'write' }">
							<input v-model="permission" type="radio" value="write">
							<Pencil :size="16" class="share-perm-icon" />
							<span class="share-perm-text">
								<strong>{{ t('souvera_mail', 'Bearbeiten') }}</strong>
								<small>{{ t('souvera_mail', 'Mails verschieben und als gelesen markieren') }}</small>
							</span>
						</label>
					</div>

					<label class="share-children">
						<input v-model="includeChildren" type="checkbox">
						{{ t('souvera_mail', 'Unterordner einbeziehen') }}
					</label>

					<div class="share-actions">
						<NcButton variant="primary" :disabled="busy" @click="grant">
							{{ t('souvera_mail', 'Freigeben') }}
						</NcButton>
					</div>
				</template>
			</section>

			<!-- Bestehende Freigaben -->
			<section v-if="grants.length > 0" class="share-section share-section--divided">
				<h4 class="share-label">{{ t('souvera_mail', 'Geteilt mit') }}</h4>
				<NcLoadingIcon v-if="loading" :size="20" />
				<ul v-else class="share-grants">
					<li v-for="g in grants" :key="g.accountId">
						<NcAvatar v-if="g.granteeUid" :user="g.granteeUid"
							:display-name="g.email || g.granteeUid" :size="34"
							:disable-menu="true" :show-user-status="false" />
						<span v-else class="share-initials">{{ initials(g.email || g.accountId) }}</span>
						<span class="share-grant-meta">
							<strong>{{ g.email || g.accountId }}</strong>
							<span class="share-rights-badge" :class="{ 'share-rights-badge--write': (g.rights || {}).mayAddItems }">
								{{ rightsLabel(g) }}
							</span>
						</span>
						<NcButton variant="tertiary" :disabled="busy" @click="revoke(g)">
							{{ t('souvera_mail', 'Entziehen') }}
						</NcButton>
					</li>
				</ul>
			</section>
			<p v-else-if="!loading" class="share-empty">
				{{ t('souvera_mail', 'Dieser Ordner ist bisher nicht freigegeben.') }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcDialog, NcButton, NcTextField, NcAvatar, NcLoadingIcon } from '@nextcloud/vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'ShareDialog',
	components: { NcDialog, NcButton, NcTextField, NcAvatar, NcLoadingIcon, Folder, Lock, Pencil, Close },
	props: {
		mailbox: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return {
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
			currentUid: (typeof window !== 'undefined' && window.OC && window.OC.getCurrentUser)
				? (window.OC.getCurrentUser().uid || '') : '',
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
	watch: {
		q() { this.onSearch() },
	},
	beforeDestroy() {
		if (this.searchTimer) clearTimeout(this.searchTimer)
	},
	methods: {
		initials(name) {
			// Echte Initialen: erste Buchstaben der ersten zwei Wörter
			const parts = (name || '?').trim().split(/\s+/).filter(Boolean)
			if (parts.length === 0) return '?'
			if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
			return (parts[0][0] + parts[1][0]).toUpperCase()
		},
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
				const params = { mailboxId: this.mailbox.id, includeChildren: 'true' }
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
/* ---------- Grundraster ---------- */
.share-dialog {
	width: 100%;
	display: flex;
	flex-direction: column;
	gap: 18px;
}
.share-section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.share-section--divided {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}
.share-label {
	margin: 0;
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .06em;
	color: var(--color-text-maxcontrast);
}

/* ---------- Ordner-Kontext ---------- */
.share-folder-card {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-background-dark);
}
.share-folder-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}
.share-folder-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ---------- Fehler ---------- */
.share-error {
	margin: 0;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: var(--color-error-bg);
	color: var(--color-error-text);
}

/* ---------- User-Suche ---------- */
.share-user-list {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
	max-height: 200px;
	overflow-y: auto;
	list-style: none;
	margin: 0;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.share-user-list li > .share-initials {
	grid-area: avatar;
}
.share-user-list li {
	display: grid;
	grid-template-columns: auto 1fr;
	grid-template-areas: "avatar name" "avatar detail";
	column-gap: 10px;
	align-items: center;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.share-user-list li:hover {
	background: var(--color-background-hover);
}
.share-user-name {
	grid-area: name;
	font-weight: 500;
}
.share-user-detail {
	grid-area: detail;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* ---------- Initialen-Kreis (ohne NC-Avatar) ---------- */
.share-initials {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	flex-shrink: 0;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: 13px;
	font-weight: 700;
}

/* ---------- Ausgewählter User (Chip) ---------- */
.share-picked-chip {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background: var(--color-background-dark);
}
.share-picked-meta {
	display: flex;
	flex-direction: column;
	line-height: 1.3;
	flex: 1;
	min-width: 0;
}
.share-picked-meta small {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* ---------- Berechtigungs-Karten ---------- */
.share-perm-cards {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}
.share-perm-card {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 12px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-container);
	cursor: pointer;
	transition: border-color .1s ease-in-out, background-color .1s ease-in-out;
}
.share-perm-card input[type='radio'] {
	margin-top: 2px;
}
.share-perm-card--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}
.share-perm-icon {
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	flex-shrink: 0;
}
.share-perm-card--active .share-perm-icon {
	color: var(--color-primary-element);
}
.share-perm-text {
	display: flex;
	flex-direction: column;
	line-height: 1.35;
}
.share-perm-text small {
	color: var(--color-text-maxcontrast);
}

/* ---------- Unterordner + Aktionen ---------- */
.share-children {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	padding: 2px 0;
}
.share-actions {
	display: flex;
	justify-content: flex-end;
}

/* ---------- Bestehende Freigaben ---------- */
.share-grants {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.share-grants li {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border-radius: var(--border-radius-container);
	transition: background-color .1s ease-in-out;
}
.share-grants li:hover {
	background: var(--color-background-hover);
}
.share-grant-meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	line-height: 1.3;
	flex: 1;
	min-width: 0;
}
.share-grant-meta strong {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.share-rights-badge {
	align-self: flex-start;
	font-size: 11px;
	font-weight: 600;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	width: fit-content;
}
.share-rights-badge--write {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

/* ---------- Empty state ---------- */
.share-empty {
	margin: 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 8px 0 2px;
}
</style>
