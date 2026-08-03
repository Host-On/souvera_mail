<template>
	<NcModal v-model:show="dialogOpen" size="large" :name="t('souvera_mail', 'Contacts')" @close="$emit('close')">
		<div class="contact-picker">
			<div class="contact-picker__search">
				<NcTextField v-model="query" :placeholder="t('souvera_mail', 'Search contacts…')"
					@update:modelValue="doSearch" />
			</div>
			<div class="contact-picker__toolbar">
				<NcButton variant="tertiary" size="small" @click="newContact">
					<template #icon><AccountPlus :size="16" /></template>
					{{ t('souvera_mail', 'New contact') }}
				</NcButton>
				<NcButton v-if="selected.length > 0" variant="primary" size="small" @click="composeMail">
					<template #icon><Pencil :size="16" /></template>
					{{ t('souvera_mail', 'Compose mail') }}
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
							<span>{{ email }}</span>
							<NcButton variant="tertiary" size="small"
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
				<NcChip v-for="(r, i) in selected" :key="i"
					:text="r.name ? r.name + ' &lt;' + r.email + '&gt;' : r.email"
					:closeable="true" @close="selected.splice(i, 1)" />
			</div>
			<div class="contact-picker__footer">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('souvera_mail', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcAvatar, NcEmptyContent, NcChip } from '@nextcloud/vue'
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
.contact-picker { display: flex; flex-direction: column; padding: 20px; min-height: 350px; max-height: 70vh; }
.contact-picker__search { margin-bottom: 10px; }
.contact-picker__toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.contact-picker__loading { display: flex; justify-content: center; padding: 48px; }
.contact-picker__list { flex: 1; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.contact-picker__item {
	display: flex; gap: 10px; padding: 10px 14px; cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}
.contact-picker__item:last-child { border-bottom: none; }
.contact-picker__item:hover { background: var(--color-background-hover); }
.contact-picker__item--selected { background: var(--color-primary-element-light); }
.contact-picker__info { flex: 1; min-width: 0; }
.contact-picker__name { font-weight: 500; font-size: 14px; margin-bottom: 4px; }
.contact-picker__email { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--color-text-maxcontrast); padding: 2px 0; }
.contact-picker__selected { margin-top: 12px; padding: 10px 0; }
.contact-picker__selected-header { font-size: 12px; color: var(--color-text-maxcontrast); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.contact-picker__footer { display: flex; justify-content: flex-end; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-border); }
</style>
