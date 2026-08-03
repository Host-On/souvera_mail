<template>
	<NcModal v-if="dialogOpen" size="normal" @close="$emit('close')">
		<div class="contact-picker" style="padding:20px">
			<h2>{{ t('souvera_mail', 'Contacts') }}</h2>
			<div class="contact-picker__search">
				<NcTextField v-model="query" :placeholder="t('souvera_mail', 'Search contacts…')"
					@update:modelValue="doSearch" />
			</div>
			<div v-if="loading" class="contact-picker__loading"><span class="icon-loading" /></div>
			<div v-else class="contact-picker__list">
				<div v-for="contact in contacts" :key="contact.primaryEmail" class="contact-picker__item"
					@click="selectContact(contact)">
					<NcAvatar :display-name="contact.name || contact.primaryEmail" :size="36" />
					<div class="contact-picker__info">
						<div class="contact-picker__name">{{ contact.name || contact.primaryEmail }}</div>
						<div v-for="email in contact.emails" :key="email" class="contact-picker__email">
							{{ email }}
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Add as recipient')"
								@click.stop="addRecipient(email, contact.name)">
								<template #icon><Plus :size="14" /></template>
							</NcButton>
						</div>
					</div>
				</div>
			</div>
			<div v-if="addedRecipients.length > 0" class="contact-picker__selected">
				<span class="contact-picker__selected-label">{{ selected.length }} {{ t('souvera_mail', 'selected') }}</span>
			</div>
			<div class="contact-picker__footer">
				<NcButton variant="primary" @click="confirmSelection">
					{{ t('souvera_mail', 'Add selected') }}
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('souvera_mail', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField, NcAvatar } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let timer = null

export default {
	name: 'ContactPicker',
	components: { NcModal, NcButton, NcTextField, NcAvatar, Plus },
	emits: ['close', 'select'],
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
					if (this.query.trim()) {
						const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/contacts/search'), {
							params: { q: this.query.trim(), limit: 50 },
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
			}, this.query ? 300 : 0)
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
		confirmSelection() {
			if (this.selected.length > 0) {
				this.$emit('select', this.selected)
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.contact-picker { display: flex; flex-direction: column; min-height: 350px; max-height: 65vh; }
.contact-picker__search { margin-bottom: 12px; }
.contact-picker__loading { display: flex; justify-content: center; padding: 48px; }
.contact-picker__list { flex: 1; overflow-y: auto; }
.contact-picker__item {
	display: flex; gap: 10px; padding: 8px 12px; cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}
.contact-picker__item:hover { background: var(--color-background-hover); }
.contact-picker__info { flex: 1; min-width: 0; }
.contact-picker__name { font-weight: 500; font-size: 14px; margin-bottom: 2px; }
.contact-picker__email { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--color-text-maxcontrast); }
.contact-picker__selected { margin-top: 8px; padding: 4px 0; }
.contact-picker__selected-label { font-size: 13px; color: var(--color-primary-element); font-weight: 500; }
.contact-picker__footer { display: flex; gap: 8px; justify-content: flex-end; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-border); }
</style>
