<template>
	<NcModal v-model:show="dialogOpen" size="large" :name="t('souvera_mail', 'Contacts')" @close="$emit('close')">
		<div class="contact-picker">
			<div class="contact-picker__searchrow">
				<NcTextField v-model="query" class="contact-picker__search"
					:placeholder="t('souvera_mail', 'Search contacts…')"
					@update:modelValue="doSearch" />
				<NcButton variant="tertiary" @click="newContact">
					<template #icon><AccountPlus :size="18" /></template>
					{{ t('souvera_mail', 'New contact') }}
				</NcButton>
			</div>

			<div v-if="loading" class="contact-picker__loading"><span class="icon-loading" /></div>
			<div v-else class="contact-picker__list">
				<div v-for="contact in contacts" :key="contact.primaryEmail" class="contact-picker__item"
					:class="{ 'contact-picker__item--selected': isSelected(contact) }"
					@click="selectContact(contact)">
					<NcAvatar :display-name="contact.name || contact.primaryEmail" :size="40" />
					<div class="contact-picker__info">
						<div class="contact-picker__name">{{ contact.name || contact.primaryEmail }}</div>
						<div v-for="email in contact.emails" :key="email" class="contact-picker__email">
							<span class="contact-picker__email-addr">{{ email }}</span>
							<NcButton variant="tertiary" size="small" class="contact-picker__add-btn"
								:aria-label="t('souvera_mail', 'Add as recipient')"
								@click.stop="addRecipient(email, contact.name)">
								<template #icon><Plus :size="14" /></template>
							</NcButton>
						</div>
					</div>
				</div>
				<NcEmptyContent v-if="!loading && contacts.length === 0"
					:name="t('souvera_mail', 'No contacts found')" />
			</div>

			<div v-if="selected.length > 0" class="contact-picker__selected">
				<div class="contact-picker__selected-header">
					{{ selected.length }} {{ t('souvera_mail', 'selected') }}
				</div>
				<div class="contact-picker__chips">
					<NcChip v-for="(r, i) in selected" :key="r.email"
						:text="r.name ? r.name + ' &lt;' + r.email + '&gt;' : r.email"
						:closeable="true" @close="selected.splice(i, 1)" />
				</div>
			</div>

			<div class="contact-picker__footer">
				<NcButton v-if="selected.length > 0" variant="primary" @click="composeMail">
					<template #icon><Pencil :size="18" /></template>
					{{ t('souvera_mail', 'Compose mail') }}
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('souvera_mail', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcAvatar, NcEmptyContent, NcChip } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let timer = null

export default {
	name: 'ContactPicker',
	components: { NcModal, NcButton, NcTextField, NcAvatar, NcEmptyContent, NcChip, Plus, AccountPlus, Pencil },
	emits: ['close', 'select', 'compose'],
	data() {
		return {
			dialogOpen: true,
			query: '',
			contacts: [],
			selected: [],
			loading: false,
		}
	},
	mounted() { this.doSearch() },
	beforeUnmount() { clearTimeout(timer) },
	methods: {
		doSearch() {
			clearTimeout(timer)
			timer = setTimeout(async () => {
				this.loading = true
				try {
					const q = this.query.trim()
					if (q) {
						const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/contacts/search'), {
							params: { q, limit: 50 },
						})
						this.contacts = (data.contacts || []).map(c => ({
							name: c.name,
							emails: [c.email],
							primaryEmail: c.email,
						}))
					} else {
						const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/contacts/list'), {
							params: { limit: 100 },
						})
						this.contacts = data.contacts || []
					}
				} catch (e) {
					console.error('Contact list failed', e)
					this.contacts = []
					this.t && showError(this.t('souvera_mail', 'Failed to load contacts'))
				} finally { this.loading = false }
			}, q ? 300 : 0)
		},
		isSelected(contact) {
			return contact.emails.some(e => this.selected.some(r => r.email === e))
		},
		addRecipient(email, name) {
			if (!this.selected.some(r => r.email === email)) {
				this.selected.push({ name, email })
			}
		},
		selectContact(contact) {
			for (const email of contact.emails) {
				this.addRecipient(email, contact.name)
			}
		},
		newContact() {
			window.open(generateUrl('/apps/contacts'), '_blank')
		},
		composeMail() {
			this.$emit('compose', this.selected)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.contact-picker { display: flex; flex-direction: column; gap: 14px; padding: 24px; min-height: 380px; max-height: 75vh; box-sizing: border-box; }
.contact-picker__searchrow { display: flex; gap: 10px; align-items: center; }
.contact-picker__search { flex: 1; }
.contact-picker__loading { display: flex; justify-content: center; padding: 56px; }
.contact-picker__list {
	flex: 1; overflow-y: auto;
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}
.contact-picker__item {
	display: flex; gap: 12px; padding: 12px 16px; cursor: pointer;
	align-items: flex-start;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.12s;
}
.contact-picker__item:last-child { border-bottom: none; }
.contact-picker__item:hover { background: var(--color-background-hover); }
.contact-picker__item--selected { background: var(--color-primary-element-light); }
.contact-picker__info { flex: 1; min-width: 0; }
.contact-picker__name { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
.contact-picker__email {
	display: flex; align-items: center; justify-content: space-between; gap: 8px;
	font-size: 13px; color: var(--color-text-maxcontrast); padding: 2px 0;
}
.contact-picker__email-addr { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.contact-picker__add-btn { flex-shrink: 0; opacity: 0.55; }
.contact-picker__item:hover .contact-picker__add-btn,
.contact-picker__item--selected .contact-picker__add-btn { opacity: 1; }
.contact-picker__selected { padding: 4px 2px 0; }
.contact-picker__selected-header {
	font-size: 12px; color: var(--color-text-maxcontrast);
	margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;
}
.contact-picker__chips { display: flex; flex-wrap: wrap; gap: 6px; }
.contact-picker__footer {
	display: flex; justify-content: flex-end; gap: 8px;
	padding-top: 14px; border-top: 1px solid var(--color-border);
}
</style>
