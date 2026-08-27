<template>
	<div class="settings-view">
		<h1 class="settings-view__title">{{ t('souvera_mail', 'Settings') }}</h1>

		<div class="settings-grid">
			<div class="settings-card">
				<h2 class="settings-card__title">
					<Account :size="20" />
					{{ t('souvera_mail', 'Account') }}
				</h2>
				<div class="settings-card__body">
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Email') }}</span>
						<span class="setting-value">{{ accountEmail || t('souvera_mail', 'Loading…') }}</span>
					</div>
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Storage') }}</span>
						<span class="setting-value">
							<template v-if="quotaUnlimited">{{ t('souvera_mail', 'Unlimited') }}</template>
							<template v-else-if="quotaTotal > 0">{{ formatSize(quotaUsed) }} / {{ formatSize(quotaTotal) }}</template>
							<span v-else class="settings-muted">{{ t('souvera_mail', 'No quota information available') }}</span>
						</span>
					</div>
					<div v-if="quotaTotal > 0 && !quotaUnlimited" class="quota-bar">
						<div class="quota-bar__fill" :style="{ width: quotaPercent + '%' }" />
					</div>
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Version') }}</span>
						<span class="setting-value">{{ appVersion || t('souvera_mail', 'Loading…') }}</span>
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Palette :size="20" />
					{{ t('souvera_mail', 'Appearance') }}
				</h2>
				<div class="settings-card__body">
					<div class="setting-row">
						<span class="setting-label">{{ t('souvera_mail', 'Layout') }}</span>
					</div>
					<div v-if="loaded" class="layout-options">
						<label class="layout-option" :class="{ 'layout-option--active': !verticalLayout && !listOnlyLayout }"
							@click="setLayout('split')">
							<div class="layout-preview layout-preview--horizontal">
								<div class="layout-preview__sidebar"></div>
								<div class="layout-preview__detail"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'Side by side') }}</span>
						</label>
						<label class="layout-option" :class="{ 'layout-option--active': verticalLayout }"
							@click="setLayout('vertical')">
							<div class="layout-preview layout-preview--vertical">
								<div class="layout-preview__sidebar"></div>
								<div class="layout-preview__detail"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'List above, detail below') }}</span>
						</label>
						<label class="layout-option" :class="{ 'layout-option--active': listOnlyLayout && !focusLayout }"
							@click="setLayout('list')">
							<div class="layout-preview layout-preview--list">
								<div class="layout-preview__sidebar"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'List only') }}</span>
						</label>
						<label class="layout-option" :class="{ 'layout-option--active': focusLayout }"
							@click="setLayout('focus')">
							<div class="layout-preview layout-preview--focus">
								<div class="layout-preview__sidebar"></div>
								<div class="layout-preview__detail"></div>
							</div>
							<span class="layout-option__label">{{ t('souvera_mail', 'Focus reader') }}</span>
						</label>
					</div>
					<div v-if="loaded" class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'External images') }}</span>
							<p class="settings-muted">{{ t('souvera_mail', 'Remote content can be used to track you.') }}</p>
						</div>
						<NcSelect v-model="remoteImagesOption" :options="remoteImageOptions"
							label="label" class="setting-select"
							:clearable="false"
							@update:modelValue="onRemoteImagesChange" />
					</div>
					<div v-if="loaded" class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Auto-refresh') }}</span>
							<p class="settings-muted">{{ t('souvera_mail', 'Periodically check for new mail. Disabled when set to 0.') }}</p>
						</div>
						<NcSelect v-model="autoRefreshOption" :options="autoRefreshOptions"
							label="label" class="setting-select" :clearable="false"
							@update:modelValue="onAutoRefreshChange" />
					</div>
					<div class="setting-row">
						<div>
							<span class="setting-label">{{ t('souvera_mail', 'Notification sound') }}</span>
						</div>
						<div class="setting-row__sound">
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Preview sound')"
								@click="previewSound">
								<template #icon><Play :size="16" /></template>
							</NcButton>
							<NcSelect v-model="soundOption" :options="soundOptions"
								label="label" class="setting-select" :clearable="false"
								@update:modelValue="onSoundChange" />
						</div>
					</div>
					<div class="setting-row setting-row--column" v-if="identityOptions.length > 0">
						<span class="setting-label">{{ t('souvera_mail', 'Identities') }}</span>
						<span class="settings-muted">{{ t('souvera_mail', 'Tap the star to set the default sender. The signature icon stores a signature for this identity only.') }}</span>
						<div class="identity-list">
							<div v-for="i in identityOptions" :key="i.id" class="identity-row">
								<NcButton variant="tertiary" size="small"
									class="identity-row__default"
									:title="isDefaultIdentity(i) ? t('souvera_mail', 'Default sender') : t('souvera_mail', 'Set as default sender')"
									@click="setDefaultIdentity(i)">
									<template #icon>
										<Star :size="16" :class="{ 'identity-row__star--active': isDefaultIdentity(i) }" />
									</template>
								</NcButton>
								<div class="identity-row__info">
									<div class="identity-row__email">{{ i.email }}</div>
									<div class="identity-row__name">
										<template v-if="editingIdentityId === i.id">
											<NcTextField v-model="editingIdentityName" size="small" />
											<NcButton variant="primary" size="small" @click="saveIdentityName(i)">{{ t('souvera_mail', 'Save') }}</NcButton>
											<NcButton variant="tertiary" size="small" @click="editingIdentityId = null">{{ t('souvera_mail', 'Cancel') }}</NcButton>
										</template>
										<template v-else>
											{{ i.name || t('souvera_mail', '(no display name)') }}
											<NcButton v-if="!i.isExternal" variant="tertiary" size="small"
												:title="t('souvera_mail', 'Edit name')"
												@click="startEditIdentity(i)">
												<template #icon><Pencil :size="12" /></template>
											</NcButton>
											<NcButton variant="tertiary" size="small"
												:title="t('souvera_mail', 'Signature for this identity')"
												@click="openIdentitySignature(i)">
												<template #icon>
													<SignatureText :size="14" :class="{ 'identity-row__sig--active': hasIdentitySignature(i) }" />
												</template>
											</NcButton>
											<span v-if="i.isAlias" class="identity-row__alias-tag">{{ t('souvera_mail', 'Alias') }}</span>
											<span v-if="i.isExternal" class="identity-row__alias-tag identity-row__ext-tag">{{ t('souvera_mail', 'External') }}</span>
										</template>
									</div>
								</div>
							</div>
						</div>
					</div>
					<NcDialog v-if="sigIdentity" :name="t('souvera_mail', 'Signature for') + ' ' + sigIdentity.email"
						:open.sync="true"
						size="large"
						@update:open="sigIdentity = null">
						<div class="identity-sig-dialog">
							<NcCheckboxRadioSwitch :model-value="sigDialogEnabled"
								@update:modelValue="sigDialogEnabled = $event">
								{{ t('souvera_mail', 'Append this signature to messages from this identity') }}
							</NcCheckboxRadioSwitch>

							<div class="identity-sig-dialog__grid">
								<div>
									<span class="setting-label">{{ t('souvera_mail', 'Write replies') }}</span>
									<NcSelect v-model="sigDialogReplyPos" :options="replyPositionOptions" :clearable="false"
										label="label" class="setting-select" />
								</div>
								<div>
									<span class="setting-label">{{ t('souvera_mail', 'Signature position') }}</span>
									<NcSelect v-model="sigDialogSigPos" :options="signaturePositionOptions" :clearable="false"
										label="label" class="setting-select" />
								</div>
							</div>

							<span class="setting-label">{{ t('souvera_mail', 'Signature') }}</span>
							<div class="signature-editor">
								<template v-if="sigDialogShowSource">
									<textarea class="signature-textarea signature-textarea--source" v-model="sigDialogHtml"
										:placeholder="t('souvera_mail', 'HTML source code…')" rows="8" spellcheck="false" />
								</template>
								<div v-else class="signature-preview" v-html="sigDialogPreview"></div>
							</div>
							<div class="signature-editor__actions">
								<NcButton variant="tertiary" size="small" @click="sigDialogShowSource = !sigDialogShowSource">
									<template #icon><CodeTags :size="16" /></template>
									{{ sigDialogShowSource ? t('souvera_mail', 'Show preview') : t('souvera_mail', 'HTML source code') }}
								</NcButton>
								<NcButton variant="tertiary" size="small" @click="pickIdentitySignatureFile">
									<template #icon><FileUpload :size="16" /></template>
									{{ t('souvera_mail', 'Import HTML file…') }}
								</NcButton>
								<input ref="identitySigFileInput" type="file" accept=".html,.htm,text/html"
									class="hidden-file-input" @change="onIdentitySignatureFileSelected" />
							</div>
							<p class="settings-muted">{{ t('souvera_mail', 'Leave the signature empty to send without signature from this identity.') }}</p>

							<div class="identity-sig-dialog__actions">
								<NcButton variant="primary" @click="saveIdentitySignature">{{ t('souvera_mail', 'Save') }}</NcButton>
								<NcButton variant="tertiary" @click="sigIdentity = null">{{ t('souvera_mail', 'Cancel') }}</NcButton>
							</div>
						</div>
					</NcDialog>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<CalendarBlank :size="20" />
					{{ t('souvera_mail', 'Out-of-office (vacation)') }}
				</h2>
				<div class="settings-card__body">
					<NcCheckboxRadioSwitch :model-value="vacationSync"
						@update:modelValue="onVacationSyncToggle">
						{{ t('souvera_mail', 'Adopt from Nextcloud') }}
					</NcCheckboxRadioSwitch>
					<p class="settings-muted">{{ t('souvera_mail', 'The absence configured in Nextcloud (personal availability) is used as the automatic reply.') }}</p>

					<div v-if="vacationState.supported === false" class="vacation-status vacation-status--warn">
						{{ t('souvera_mail', 'Disabled on this instance. Admin: occ config:app:set --value=no dav hide_absence_settings') }}
					</div>
					<div v-else-if="!vacationSync" class="vacation-status">
						{{ t('souvera_mail', 'Sync is disabled — no out-of-office reply is sent') }}
					</div>
					<div v-else-if="vacationState.ncActive && vacationState.inEffect" class="vacation-status vacation-status--active">
						{{ t('souvera_mail', 'Active until {date}', { date: vacationState.end }) }}
						<span v-if="vacationState.replacement" class="vacation-status__meta"> · {{ t('souvera_mail', 'Replacement: {name}', { name: vacationState.replacement }) }}</span>
					</div>
					<div v-else-if="vacationState.ncActive" class="vacation-status">
						{{ t('souvera_mail', 'Planned from {date}', { date: vacationState.start }) }}
					</div>
					<div v-else class="vacation-status">
						{{ t('souvera_mail', 'No absence found (neither out-of-office nor an "Absent" period in personal availability)') }}
					</div>
					<div v-if="vacationState.vacation && vacationState.vacation.enabled" class="vacation-status vacation-status--active">
						{{ t('souvera_mail', 'Auto-reply active (Sieve)') }}
					</div>
					<p v-if="vacationState.debug && vacationState.debug.appVersion" class="settings-muted">
						{{ t('souvera_mail', 'App version:') }} {{ vacationState.debug.appVersion }}
					</p>
					<details v-if="vacationState.debug" class="vacation-debug">
						<summary>{{ t('souvera_mail', 'Diagnosis') }}</summary>
						<pre class="vacation-debug__body">{{ JSON.stringify(vacationState.debug, null, 2) }}</pre>
					</details>

					<div class="setting-row setting-row--column" v-if="showVacationEditor">
						<div class="vacation-editor">
							<div class="vacation-editor__row">
								<NcDateTimePicker v-model="vacationForm.from" type="date"
									:label="t('souvera_mail', 'From')" />
								<NcDateTimePicker v-model="vacationForm.to" type="date"
									:label="t('souvera_mail', 'Until')" />
							</div>
							<NcTextField v-model="vacationForm.short"
								:label="t('souvera_mail', 'Short text')" />
							<NcTextArea v-model="vacationForm.long"
								:label="t('souvera_mail', 'Long text (auto-reply body)')" />
							<p class="settings-muted">{{ t('souvera_mail', 'The replacement person is managed in Nextcloud.') }}</p>
							<div class="vacation-editor__actions">
								<NcButton variant="primary" :disabled="vacationSaving" @click="saveVacationToNextcloud">{{ t('souvera_mail', 'Save') }}</NcButton>
								<NcButton variant="error" :disabled="vacationSaving" @click="clearVacationInNextcloud">{{ t('souvera_mail', 'Delete') }}</NcButton>
								<NcButton variant="tertiary" @click="showVacationEditor = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
							</div>
						</div>
					</div>

					<div class="vacation-actions">
						<NcButton variant="primary" @click="openAvailabilitySettings">
							<template #icon><OpenInNew :size="16" /></template>
							{{ t('souvera_mail', 'Manage in Nextcloud') }}
						</NcButton>
						<NcButton variant="tertiary" @click="toggleVacationEditor">
							<template #icon><Pencil :size="16" /></template>
							{{ t('souvera_mail', 'Edit here') }}
						</NcButton>
						<NcButton variant="tertiary" @click="syncVacationNow">
							<template #icon><Refresh :size="16" /></template>
							{{ t('souvera_mail', 'Sync now') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Download :size="20" />
					{{ t('souvera_mail', 'Email migration') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Import your old emails from another provider.') }}</p>
					<div v-if="migrationCompleted" class="migration-completed">
						<span class="migration-completed__badge">✓ {{ t('souvera_mail', 'Import successful') }}</span>
						<NcButton variant="primary" @click="resetMigration">
							<template #icon><Refresh :size="20" /></template>
							{{ t('souvera_mail', 'Start another migration') }}
						</NcButton>
					</div>
					<NcButton v-else variant="primary" @click="openMigration">
						<template #icon><Import :size="20" /></template>
						{{ t('souvera_mail', 'Start migration assistant') }}
					</NcButton>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<ShareVariant :size="20" />
					{{ t('souvera_mail', 'Shared folders') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Control where shared mailboxes appear in your folder list.') }}</p>
					<div class="shared-position-row">
						<NcCheckboxRadioSwitch :model-value="sharedAbove" type="radio"
							@update:modelValue="setSharedPosition(true)">
							{{ t('souvera_mail', 'Show above own folders') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="!sharedAbove" type="radio"
							@update:modelValue="setSharedPosition(false)">
							{{ t('souvera_mail', 'Show below own folders') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Filter :size="20" />
					{{ t('souvera_mail', 'Filters') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Mail filters (Sieve) — sort incoming mail automatically into folders, flag, forward or discard.') }}</p>

					<div v-if="loadingSieve" class="settings-muted">{{ t('souvera_mail', 'Loading…') }}</div>

					<NcEmptyContent v-else-if="sieveScripts.length === 0"
						:name="t('souvera_mail', 'No filters yet')">
						<template #icon><Filter :size="36" /></template>
					</NcEmptyContent>

					<div v-else class="sieve-list">
						<div v-for="s in sieveScripts.filter(x => !x.isMain)" :key="s.id" class="sieve-list__item">
							<span class="sieve-list__name">{{ s.name }}</span>
							<span class="sieve-list__active" v-if="s.enabled">{{ t('souvera_mail', 'active') }}</span>
							<div class="sieve-list__actions">
								<NcButton variant="tertiary" :title="t('souvera_mail', 'Edit')" @click="editSieve(s)">
									<template #icon><Pencil :size="16" /></template>
								</NcButton>
								<NcButton variant="tertiary" :title="s.enabled ? t('souvera_mail', 'Deactivate') : t('souvera_mail', 'Activate')" @click="toggleSieve(s)">
									<template #icon><component :is="s.enabled ? 'Check' : 'Play' " :size="16" /></template>
								</NcButton>
								<NcButton variant="tertiary" :title="t('souvera_mail', 'Delete')" @click="deleteSieve(s)">
									<template #icon><TrashCan :size="16" /></template>
								</NcButton>
							</div>
						</div>
					</div>

					<NcButton variant="primary" class="sieve-list__add" @click="showSieveEditor = true">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'New filter') }}
					</NcButton>
				</div>
			</div>

			<NcDialog v-if="newSecret"
				:name="t('souvera_mail', 'App password created')"
				:open.sync="true"
				size="normal"
				@update:open="newSecret = null">
				<p class="settings-muted">{{ t('souvera_mail', 'This password is only shown once. Save it now.') }}</p>
				<div class="password-reveal">
					<code>{{ newSecret }}</code>
					<NcButton variant="tertiary" size="small"
						:title="t('souvera_mail', 'Copy')"
						@click="copySecret(newSecret)">
						<template #icon><ContentCopy :size="16" /></template>
					</NcButton>
				</div>
			</NcDialog>

			<SieveFilterEditor v-if="showSieveEditor"
				:open="showSieveEditor"
				:edit-id="editingSieve?.id || ''"
				:edit-name="editingSieve?.name || ''"
				:edit-body="editingSieve?.body || ''"
				@close="closeSieveEditor"
				@saved="refreshSieve" />

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Folder :size="20" />
					{{ t('souvera_mail', 'Folders') }}
				</h2>
				<div class="settings-card__body">
					<NcButton variant="primary" @click="showCreateFolder = true; newSubfolderParentId = null">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'New folder') }}
					</NcButton>
					<p class="settings-muted" style="font-size:12px; margin:4px 0 0">{{ t('souvera_mail', 'Drag folders to rearrange or move them into another folder.') }}</p>
					<div v-if="showCreateFolder" class="create-row">
						<NcTextField v-model="newFolderName" :placeholder="t('souvera_mail', 'Folder name')" />
						<div v-if="newSubfolderParentId" class="create-row__sub">
							{{ t('souvera_mail', 'Subfolder of') }} "{{ getFolderName(newSubfolderParentId) }}"
						</div>
						<NcButton variant="primary" @click="createFolder" :disabled="newFolderName.trim() === ''">{{ t('souvera_mail', 'Create') }}</NcButton>
						<NcButton variant="tertiary" @click="showCreateFolder = false; newSubfolderParentId = null">{{ t('souvera_mail', 'Cancel') }}</NcButton>
					</div>
					<div v-if="folderTree.length > 0" class="folder-list">
						<template v-for="f in folderTree" :key="f.id">
							<div v-if="f._heading" class="folder-heading">{{ f._heading }}</div>
							<div v-else class="folder-row"
								:style="{ paddingLeft: (16 + f.depth * 20) + 'px' }"
								:draggable="!f.isSystem"
								@dragstart="!f.isSystem && onFolderDragStart($event, f)"
								@dragover.prevent="onFolderDragOver($event, f)"
								@dragleave="onFolderDragLeave($event)"
								@drop="onFolderDrop($event, f)"
								@dragend="onFolderDragEnd($event)"
								:class="{ 'folder-row--drag-over': dragOverId === f.id, 'folder-row--dragging': dragId === f.id }">
								<span class="folder-row__name">{{ f.name }}</span>
								<div class="folder-row__actions">
									<NcButton variant="tertiary" size="small"
										:aria-label="t('souvera_mail', 'Subfolder')"
										@click="newSubfolderParentId = f.id; newFolderName = ''; showCreateFolder = true">
										<template #icon><FolderPlus :size="14" /></template>
									</NcButton>
									<NcButton v-if="!f.isSystem" variant="tertiary" size="small"
										:aria-label="t('souvera_mail', 'Rename')" @click="startRenameFolder(f)">
										<template #icon><Pencil :size="14" /></template>
									</NcButton>
									<NcButton v-if="!f.isSystem" variant="tertiary" size="small"
										:aria-label="t('souvera_mail', 'Delete')" @click="deleteFolder(f.id)">
										<template #icon><TrashCan :size="14" /></template>
									</NcButton>
								</div>
							</div>
						</template>
					</div>
					<NcEmptyContent v-else-if="loadedFolders" :name="t('souvera_mail', 'No custom folders')" />
				</div>
			</div>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Email :size="20" />
					{{ t('souvera_mail', 'External accounts') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Add external IMAP/SMTP accounts (e.g. GMX, Web.de, Gmail). They appear in the navigation under "External accounts" and can be used as senders.') }}</p>

					<div v-if="extAccounts.length > 0" class="password-list">
						<div v-for="a in extAccounts" :key="a.id" class="password-row">
							<div class="password-info">
								<div class="password-name">{{ a.email }}</div>
								<div class="settings-muted">{{ a.provider || a.imap_host }}</div>
							</div>
							<NcButton variant="tertiary" size="small" :title="t('souvera_mail', 'Test connection')" @click="testExtAccount(a)">
								<template #icon><Check :size="16" /></template>
							</NcButton>
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Delete')" @click="removeExtAccount(a.id)">
								<template #icon><TrashCan :size="16" /></template>
							</NcButton>
						</div>
					</div>

					<NcButton variant="primary" @click="openExtAccountForm">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'Add external account') }}
					</NcButton>
				</div>
			</div>

			<NcDialog v-if="showExtAccountForm"
				:name="t('souvera_mail', 'Add external account')"
				:open.sync="true"
				size="large"
				@update:open="showExtAccountForm = false">
				<div class="ext-account-form">
					<div class="ext-account-form__field">
						<label>{{ t('souvera_mail', 'Email address') }}</label>
						<NcTextField v-model="extForm.email" placeholder="user@web.de" @update:value="onExtEmailChange" />
					</div>
					<div class="ext-account-form__field">
						<label>{{ t('souvera_mail', 'Password') }}</label>
						<NcTextField v-model="extForm.password" type="password" />
					</div>

					<!-- Auto-config summary (preset found, manual collapsed) -->
					<div v-if="extForm.imap_host && !extManualMode" class="ext-account-form__preset-summary">
						<div class="ext-account-form__preset-row">
							<span class="ext-account-form__preset-label">IMAP</span>
							<code>{{ extForm.imap_host }}:{{ extForm.imap_port }} ({{ sslLabel(extForm.imap_ssl) }})</code>
						</div>
						<div class="ext-account-form__preset-row">
							<span class="ext-account-form__preset-label">SMTP</span>
							<code>{{ extForm.smtp_host }}:{{ extForm.smtp_port }} ({{ sslLabel(extForm.smtp_ssl) }})</code>
						</div>
						<NcButton variant="tertiary" size="small" @click="extManualMode = true">
							{{ t('souvera_mail', 'Manual configuration') }}
						</NcButton>
					</div>

					<!-- Manual configuration -->
					<template v-if="extManualMode">
						<div class="ext-account-form__section">
							<label class="ext-account-form__section-title">{{ t('souvera_mail', 'Incoming server (IMAP)') }}</label>
							<div class="ext-account-form__row">
								<div class="ext-account-form__field" style="flex:2">
									<label>{{ t('souvera_mail', 'Host') }}</label>
									<NcTextField v-model="extForm.imap_host" placeholder="imap.example.com" />
								</div>
								<div class="ext-account-form__field" style="flex:1">
									<label>{{ t('souvera_mail', 'Port') }}</label>
									<NcTextField v-model="extForm.imap_port" type="number" />
								</div>
								<div class="ext-account-form__field" style="flex:1">
									<label>{{ t('souvera_mail', 'Security') }}</label>
									<select v-model="extForm.imap_ssl" class="native-select">
										<option value="ssl">SSL/TLS</option>
										<option value="starttls">STARTTLS</option>
										<option value="none">{{ t('souvera_mail', 'None') }}</option>
									</select>
								</div>
							</div>
						</div>
						<div class="ext-account-form__section">
							<label class="ext-account-form__section-title">{{ t('souvera_mail', 'Outgoing server (SMTP)') }}</label>
							<div class="ext-account-form__row">
								<div class="ext-account-form__field" style="flex:2">
									<label>{{ t('souvera_mail', 'Host') }}</label>
									<NcTextField v-model="extForm.smtp_host" placeholder="smtp.example.com" />
								</div>
								<div class="ext-account-form__field" style="flex:1">
									<label>{{ t('souvera_mail', 'Port') }}</label>
									<NcTextField v-model="extForm.smtp_port" type="number" />
								</div>
								<div class="ext-account-form__field" style="flex:1">
									<label>{{ t('souvera_mail', 'Security') }}</label>
									<select v-model="extForm.smtp_ssl" class="native-select">
										<option value="ssl">SSL/TLS</option>
										<option value="starttls">STARTTLS</option>
										<option value="none">{{ t('souvera_mail', 'None') }}</option>
									</select>
								</div>
							</div>
						</div>
						<div class="ext-account-form__field">
							<label>{{ t('souvera_mail', 'Username') }}</label>
							<NcTextField v-model="extForm.username" :placeholder="extForm.email || 'user@example.com'" />
						</div>
					</template>

					<!-- If no preset was found and manual not open, offer manual -->
					<NcButton v-if="!extForm.imap_host && !extManualMode" variant="tertiary" @click="extManualMode = true">
						{{ t('souvera_mail', 'Manual configuration') }}
					</NcButton>

					<div class="ext-account-form__actions">
						<div class="ext-account-form__test-result" :class="{ 'ext-account-form__test-result--ok': extTestOk, 'ext-account-form__test-result--err': extTestError }">
							<span v-if="extTestError">{{ extTestError }}</span>
							<span v-else-if="extTestOk">✓ {{ t('souvera_mail', 'Connection successful') }}</span>
						</div>
						<div class="ext-account-form__buttons">
							<NcButton variant="secondary" @click="showExtAccountForm = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
							<NcButton variant="tertiary" @click="testExtConnection"
								:disabled="!extForm.email || !extForm.imap_host || !extForm.password || extTesting">
								<template #icon><Check :size="16" /></template>
								{{ extTesting ? t('souvera_mail', 'Testing…') : t('souvera_mail', 'Test connection') }}
							</NcButton>
							<NcButton variant="primary" @click="addExtAccount"
								:disabled="!extTestOk || extTesting">
								{{ t('souvera_mail', 'Add account') }}
							</NcButton>
						</div>
					</div>
				</div>
			</NcDialog>

			<div class="settings-card">
				<h2 class="settings-card__title">
					<Key :size="20" />
					{{ t('souvera_mail', 'App passwords') }}
				</h2>
				<div class="settings-card__body">
					<p class="settings-muted">{{ t('souvera_mail', 'Create device-specific passwords for mail clients and mobile apps.') }}</p>
					<NcButton variant="primary" @click="showCreate = true">
						<template #icon><Plus :size="20" /></template>
						{{ t('souvera_mail', 'New app password') }}
					</NcButton>
					<div v-if="showCreate" class="create-row">
						<NcTextField v-model="newName" :placeholder="t('souvera_mail', 'Name (e.g. Android, iOS)')" />
						<NcButton variant="primary" @click="create" :disabled="newName.trim() === ''">{{ t('souvera_mail', 'Create') }}</NcButton>
						<NcButton variant="tertiary" @click="showCreate = false">{{ t('souvera_mail', 'Cancel') }}</NcButton>
					</div>
					<div v-if="passwords.length > 0" class="password-list">
						<div v-for="pw in passwords" :key="pw.id" class="password-row">
							<div class="password-info">
								<div class="password-name">{{ pw.description || pw.name }}</div>
								<div class="settings-muted">{{ t('souvera_mail', 'Created:') }} {{ pw.createdAt ? fmtDate(pw.createdAt) : '—' }}</div>
								<div class="settings-muted password-last-used"
									:title="pw.lastUsedAt ? fmtDate(pw.lastUsedAt) : ''">
									{{ t('souvera_mail', 'Last used:') }} {{ fmtLastUsed(pw.lastUsedAt) }}
								</div>
							</div>
							<NcButton variant="tertiary" size="small"
								:aria-label="t('souvera_mail', 'Delete')" @click="remove(pw.id)">
								<template #icon><TrashCan :size="16" /></template>
							</NcButton>
						</div>
					</div>
					<NcEmptyContent v-else-if="loaded && passwords.length === 0"
						:name="t('souvera_mail', 'No app passwords')" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcTextArea, NcDateTimePicker, NcCheckboxRadioSwitch, NcSelect, NcEmptyContent, NcDialog } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCan from 'vue-material-design-icons/TrashCan.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Star from 'vue-material-design-icons/Star.vue'
import SignatureText from 'vue-material-design-icons/SignatureText.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import Key from 'vue-material-design-icons/Key.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import FileUpload from 'vue-material-design-icons/FileUpload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Play from 'vue-material-design-icons/Play.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Filter from 'vue-material-design-icons/Filter.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Email from 'vue-material-design-icons/Email.vue'
import DOMPurify from 'dompurify'
import QuotaDonut from '../components/QuotaDonut.vue'
import SieveFilterEditor from '../components/SieveFilterEditor.vue'
import { useSieveClient } from '../composables/useSieveClient.js'
import { useExternalAccounts } from '../composables/useExternalAccounts.js'

const { fetchScripts, deleteScript, rebuild } = useSieveClient()
const extAccountsApi = useExternalAccounts()
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'

const API = {
	quota: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/quota')),
	passwords: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords')),
	shared: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/shared')),
	prefs: () => axios.get(generateUrl('/apps/souvera_mail/api/v2/settings/preferences')),
	savePrefs: (data) => axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), data),
}

export default {
	name: 'SettingsView',
	components: { NcButton, NcTextField, NcTextArea, NcDateTimePicker, NcCheckboxRadioSwitch, NcSelect, NcEmptyContent, NcDialog, Plus, TrashCan, Account, Palette, Pencil, Star, SignatureText, ShareVariant, Key, Folder, CodeTags, FileUpload, Download, Import, Refresh, Play, FolderPlus, Filter, Check, ContentCopy, Email, CalendarBlank, OpenInNew, QuotaDonut, SieveFilterEditor },
	data() {
		return {
			accountEmail: '',
			quotaUsed: 0, quotaTotal: 0, quotaUnlimited: false,
			appVersion: '',
			passwords: [], showCreate: false, newName: '', newSecret: null,
			sharedAbove: true,
			replyPositionOptions: [
				{ value: 'above', label: this.t ? this.t('souvera_mail', 'Above the quoted text') : 'Above the quoted text' },
				{ value: 'below', label: this.t ? this.t('souvera_mail', 'Below the quoted text') : 'Below the quoted text' },
			],
			signaturePositionOptions: [
				{ value: 'above', label: this.t ? this.t('souvera_mail', 'Above the quoted text') : 'Above the quoted text' },
				{ value: 'below', label: this.t ? this.t('souvera_mail', 'Below the quoted text') : 'Below the quoted text' },
			],
			loaded: false,
			migrationCompleted: false,
			remoteImageOptions: [
				{ value: 'never', label: this.t ? this.t('souvera_mail', 'Ask before loading') : 'Ask before loading' },
				{ value: 'always', label: this.t ? this.t('souvera_mail', 'Always load') : 'Always load' },
			],
			remoteImagesOption: { value: 'never', label: 'Ask before loading' },
			verticalLayout: false,
			listOnlyLayout: false,
			focusLayout: false,
			autoRefreshOptions: [
				{ value: 0, label: 'Off' },
				{ value: 30, label: '30s' },
				{ value: 60, label: '1m' },
				{ value: 120, label: '2m' },
				{ value: 300, label: '5m' },
			],
			autoRefreshOption: { value: 60, label: '1m' },
			soundOptions: [
				{ value: 'none', label: 'Off' },
				{ value: 'chime', label: 'Chime' },
				{ value: 'bell', label: 'Bell' },
				{ value: 'new-mail', label: 'New mail' },
				{ value: 'alert', label: 'Alert' },
				{ value: 'ping', label: 'Ping' },
			],
			soundOption: { value: 'none', label: 'Off' },
			userFoldersList: [],
			showCreateFolder: false,
			newFolderName: '',
			newSubfolderParentId: null,
			dragId: null,
			dragOverId: null,
			loadedFolders: false,
			allMailboxesList: [],

			sieveScripts: [],
			loadingSieve: false,
			showSieveEditor: false,
			editingSieve: null,

			extAccounts: [],
			showExtAccountForm: false,
			extManualMode: false,
			extTestOk: false,
			extTestError: '',
			extTesting: false,
			extForm: { email: '', imap_host: '', imap_port: 993, imap_ssl: 'ssl', smtp_host: '', smtp_port: 465, smtp_ssl: 'ssl', username: '', password: '', provider: '' },

			identityOptions: [],
			_prefsDefaultIdentityId: '',
			editingIdentityId: null,
			editingIdentityName: '',
			aliasDisplayNames: {},
			identitySignatures: {},
			vacationSync: true,
			vacationState: { supported: true, ncActive: false, inEffect: false, start: '', end: '', short: '', long: '', replacement: '' },
			showVacationEditor: false,
			vacationSaving: false,
			vacationForm: { from: null, to: null, short: '', long: '' },
			sigIdentity: null,
			sigDialogHtml: '',
			sigDialogEnabled: false,
			sigDialogShowSource: false,
			sigDialogReplyPos: null,
			sigDialogSigPos: null,
		}
	},
	mounted() {
		this.remoteImageOptions[0].label = this.t('souvera_mail', 'Ask before loading')
		this.remoteImageOptions[1].label = this.t('souvera_mail', 'Always load')
		this.loadAll()
	},
	computed: {
		sigDialogPreview() {
			return DOMPurify.sanitize(this.sigDialogHtml || '', { USE_PROFILES: { html: true } })
		},
		// Flattened tree: roots first, then children nested under parents.
		// System folders (inbox, sent, …) are included as anchor nodes so
		// that any child folder underneath them appears correctly indented.
		folderTree() {
			const list = this.userFoldersList
			const byId = {}
			for (const f of list) { byId[f.id] = { ...f, children: [], isSystem: false } }

			// Collect parent IDs that are not in the list itself (system folders).
			const missingParents = new Set()
			for (const id in byId) {
				const f = byId[id]
				if (f.parentId && !byId[f.parentId]) {
					missingParents.add(f.parentId)
				}
			}
			// Fetch missing parent info from the full mailbox list
			for (const pid of missingParents) {
				const mb = this.allMailboxesList?.find(m => m.id === pid)
				const name = mb ? (mb.name || pid) : pid
				byId[pid] = { id: pid, name, children: [], isSystem: true, depth: 0, role: mb?.role }
			}
			// Also add system mailboxes that can have subfolders (Inbox, Drafts, Sent)
			const wantedRoles = ['inbox', 'drafts', 'sent']
			for (const mb of (this.allMailboxesList || [])) {
				if (!wantedRoles.includes(mb.role)) continue
				if (byId[mb.id]) continue
				byId[mb.id] = { id: mb.id, name: mb.name || mb.id, children: [], isSystem: true, depth: 0, role: mb.role }
			}

			const roots = []
			for (const id in byId) {
				const f = byId[id]
				if (f.parentId && byId[f.parentId]) {
					byId[f.parentId].children.push(f)
				} else { roots.push(f) }
			}
			const flat = []
			const systemRoots = []; const userRoots = []
			for (const r of roots) {
				(r.isSystem ? systemRoots : userRoots).push(r)
			}
			function walk(nodes, depth) {
				nodes.sort((a, b) => (a.name || '').localeCompare(b.name || ''))
				for (const n of nodes) {
					flat.push({ ...n, depth })
					walk(n.children, depth + 1)
				}
			}
			// Show user roots first, then system-folder subtrees (collapsed by default).
			walk(userRoots, 0)
			if (systemRoots.length) {
				flat.push({ _heading: 'System folders', depth: 0, id: '_system' })
				walk(systemRoots, 1)
			}
			return flat
		},
		quotaPercent() {
			if (this.quotaTotal <= 0) return 0
			return Math.min(100, Math.round((this.quotaUsed / this.quotaTotal) * 100))
		},
	},
	watch: {
		// Any change to the external-account form invalidates the
		// successful connection test — the user must re-test.
		extForm: {
			deep: true,
			handler() {
				if (this.extTestOk || this.extTestError) {
					this.extTestOk = false
					this.extTestError = ''
				}
			},
		},
	},
	methods: {
		async loadAll() {
			try { const r = await API.quota(); this.quotaUsed = r.data.used || 0; this.quotaTotal = r.data.total || 0; this.quotaUnlimited = r.data.unlimited || false } catch {}
			try { const r = await API.passwords(); this.passwords = r.data.passwords || [] } catch {}
			try { const r = await API.shared(); this.sharedAbove = r.data.position === 'above' } catch {}
			try { const r = await axios.get(generateUrl('/apps/souvera_mail/migration/welcome-state')); const s = r.data?.state?.lastJob?.state; this.migrationCompleted = ['completed','dismissed','failed','cancelled'].includes(s) } catch {}
			try { const r = await axios.get(generateUrl('/apps/souvera_mail/api/v2/mailboxes')); this.allMailboxesList = r.data.mailboxes || []; this.userFoldersList = this.allMailboxesList.filter(m => !['inbox','sent','drafts','junk','trash'].includes(m.role)) } catch {} finally { this.loadedFolders = true }
			this.loadSieve()
			try {
				const r = await API.prefs(); const p = r.data
				this.accountEmail = (p.account && p.account.email) || ''
				this.appVersion = (p.account && p.account.version) || ''
				// Legacy global signature: migrated into the primary identity
				// on first load (see loadIdentityOptions).
				this._pendingGlobalSig = p.signatureHtml || ''
				this._pendingGlobalSigEnabled = !!p.signatureEnabled
				const ri = this.remoteImageOptions.find(o => o.value === (p.remoteImages || 'never'))
				if (ri) this.remoteImagesOption = ri
				this.verticalLayout = p.verticalLayout || false
				this.listOnlyLayout = p.listOnlyLayout || false
				this.focusLayout = p.focusLayout || false
				const ar = this.autoRefreshOptions.find(o => o.value === (p.autoRefresh || 60))
				if (ar) this.autoRefreshOption = ar
				const so = this.soundOptions.find(o => o.value === (p.notificationSound || 'none'))
				if (so) this.soundOption = so
				this.vacationSync = p.vacationSync !== false
				this.accountUid = (p.account && p.account.uid) || ''
				this._prefsDefaultIdentityId = p.defaultIdentityId || ''
				this.aliasDisplayNames = p.aliasDisplayNames || {}
				this.identitySignatures = p.identitySignatures || {}
			} catch {}
			this.loaded = true
			this.loadIdentityOptions()
			this.loadExternalAccounts()
			this.loadVacationState()
		},
		async loadSieve() {
			this.loadingSieve = true
			try {
				this.sieveScripts = await fetchScripts()
			} catch {}
			this.loadingSieve = false
		},
		async refreshSieve() { await this.loadSieve() },
		async loadIdentityOptions() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/api/v2/identities'))
				const list = (data.identities || []).map(i => {
					const isAlias = !!i.isAlias
					const name = (i.name || '').trim() || (isAlias ? (this.aliasDisplayNames[(i.email || '').toLowerCase()] || '') : '')
					return { id: i.id, label: name ? `${name} <${i.email}>` : i.email, value: i.id, name, email: i.email, isAlias, isExternal: !!i.isExternal }
				})
				this.identityOptions = list
				await this.migrateLegacySignature(list)
			} catch {}
		},
		// The old GLOBAL signature (pre per-identity era) is moved into the
		// primary identity once, so existing users keep their signature.
		async migrateLegacySignature(list) {
			if (this._sigMigrated || !this._pendingGlobalSig || list.length === 0) return
			this._sigMigrated = true
			// Fresh server snapshot right before writing — another tab may
			// have stored identity signatures in the meantime.
			try {
				const { data } = await API.prefs()
				if (Object.keys(data.identitySignatures || {}).length > 0) return
			} catch { return }
			const primary = list.find(i => !i.isAlias && !i.isExternal) || list[0]
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/identities/' + encodeURIComponent(primary.id) + '/signature'), {
					html: this._pendingGlobalSig,
					enabled: !!this._pendingGlobalSigEnabled,
					signaturePosition: 'above',
					replyPosition: 'above',
				})
				this.identitySignatures = {
					...this.identitySignatures,
					[primary.id]: {
						html: this._pendingGlobalSig,
						enabled: !!this._pendingGlobalSigEnabled,
						signaturePosition: 'above',
						replyPosition: 'above',
					},
				}
				showSuccess(this.t('souvera_mail', 'Your previous signature was moved to the identity') + ' ' + primary.email)
			} catch {}
		},
		isDefaultIdentity(i) {
			return this._prefsDefaultIdentityId === i.id
		},
		async setDefaultIdentity(i) {
			try {
				await API.savePrefs({ defaultIdentityId: i.id })
				this._prefsDefaultIdentityId = i.id
				showSuccess(this.t('souvera_mail', 'Default sender saved'))
			} catch { showError(this.t('souvera_mail', 'Failed to save')) }
		},
		hasIdentitySignature(i) {
			const entry = this.identitySignatures[i.id]
			return !!(entry && entry.enabled && entry.html)
		},
		openIdentitySignature(i) {
			const entry = this.identitySignatures[i.id] || null
			this.sigIdentity = i
			this.sigDialogHtml = entry ? entry.html : ''
			this.sigDialogEnabled = !!(entry && entry.enabled)
			this.sigDialogShowSource = false
			this.sigDialogReplyPos = this.replyPositionOptions.find(o => o.value === (entry?.replyPosition || 'above')) || this.replyPositionOptions[0]
			this.sigDialogSigPos = this.signaturePositionOptions.find(o => o.value === (entry?.signaturePosition || 'above')) || this.signaturePositionOptions[0]
		},
		async saveIdentitySignature() {
			if (!this.sigIdentity) return
			const id = this.sigIdentity.id
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/identities/' + encodeURIComponent(id) + '/signature'), {
					html: this.sigDialogHtml,
					enabled: this.sigDialogEnabled,
					signaturePosition: this.sigDialogSigPos?.value || 'above',
					replyPosition: this.sigDialogReplyPos?.value || 'above',
				})
				const names = { ...this.identitySignatures }
				names[id] = {
					html: this.sigDialogHtml,
					enabled: this.sigDialogHtml.trim() !== '' && this.sigDialogEnabled,
					signaturePosition: this.sigDialogSigPos?.value || 'above',
					replyPosition: this.sigDialogReplyPos?.value || 'above',
				}
				this.identitySignatures = names
				this.sigIdentity = null
				showSuccess(this.t('souvera_mail', 'Signature saved'))
			} catch (e) {
				showError(e?.response?.data?.error || this.t('souvera_mail', 'Failed to save signature'))
			}
		},
		pickIdentitySignatureFile() { this.$refs.identitySigFileInput?.click() },
		onIdentitySignatureFileSelected(e) {
			const file = e.target.files?.[0]
			e.target.value = ''
			if (!file) return
			if (file.size === 0 || file.size > 2 * 1024 * 1024) {
				showError(this.t('souvera_mail', 'Signature file ignored (empty or larger than 2 MB)'))
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				const raw = String(reader.result || '')
				this.sigDialogHtml = DOMPurify.sanitize(raw, { USE_PROFILES: { html: true } })
				this.sigDialogShowSource = false
			}
			reader.onerror = () => {
				console.error('Failed to read signature file')
				showError(this.t('souvera_mail', 'Failed to read signature file'))
			}
			reader.readAsText(file)
		},
		startEditIdentity(identity) {
			this.editingIdentityId = identity.id
			this.editingIdentityName = identity.name || ''
		},
		async saveIdentityName(identity) {
			try {
				if (identity.isAlias) {
					const names = { ...this.aliasDisplayNames }
					const key = (identity.email || '').toLowerCase()
					const name = this.editingIdentityName.trim()
					if (name) names[key] = name
					else delete names[key]
					await API.savePrefs({ aliasDisplayNames: names })
					this.aliasDisplayNames = names
				} else {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/identities/' + identity.id), { name: this.editingIdentityName })
				}
				identity.name = this.editingIdentityName.trim()
				identity.label = identity.name ? `${identity.name} <${identity.email}>` : identity.email
				this.editingIdentityId = null
				showSuccess(this.t('souvera_mail', 'Identity saved'))
			} catch (e) {
				showError(e?.response?.data?.error || this.t('souvera_mail', 'Failed to save identity'))
			}
		},
		// External accounts
		async loadExternalAccounts() {
			try {
				const { list } = extAccountsApi
				this.extAccounts = await list()
			} catch {}
		},
		openExtAccountForm() {
			this.extManualMode = false
			this.extTestOk = false
			this.extTestError = ''
			this.extForm = { email: '', imap_host: '', imap_port: 993, imap_ssl: 'ssl', smtp_host: '', smtp_port: 465, smtp_ssl: 'ssl', username: '', password: '', provider: '' }
			this.showExtAccountForm = true
		},
		sslLabel(ssl) {
			if (ssl === 'starttls') return 'STARTTLS'
			if (ssl === 'none') return this.t('souvera_mail', 'None')
			return 'SSL/TLS'
		},
		async onExtEmailChange(email) {
			if (!email || !email.includes('@')) return
			if (this.extManualMode) return
			try {
				const { preset } = extAccountsApi
				const p = await preset(email)
				if (p) {
					this.extForm.imap_host = p.imap_host || ''
					this.extForm.imap_port = p.imap_port || 993
					this.extForm.imap_ssl = p.imap_ssl || 'ssl'
					this.extForm.smtp_host = p.smtp_host || ''
					this.extForm.smtp_port = p.smtp_port || 465
					this.extForm.smtp_ssl = p.smtp_ssl || 'ssl'
					this.extForm.username = p.username || email
					this.extForm.provider = p.provider || ''
				}
			} catch {}
		},
		async testExtConnection() {
			this.extTesting = true
			this.extTestOk = false
			this.extTestError = ''
			try {
				const { testConnection } = extAccountsApi
				const r = await testConnection({ ...this.extForm })
				if (r.ok) {
					this.extTestOk = true
					showSuccess(this.t('souvera_mail', 'Connection successful'))
				} else {
					this.extTestError = r.error || this.t('souvera_mail', 'Connection failed')
				}
			} catch (e) {
				this.extTestError = e?.response?.data?.error || e?.message || this.t('souvera_mail', 'Connection failed')
			} finally {
				this.extTesting = false
			}
		},
		async addExtAccount() {
			try {
				const { create } = extAccountsApi
				// Username defaults to the email address when empty
				if (!this.extForm.username) this.extForm.username = this.extForm.email
				await create({ ...this.extForm })
				this.showExtAccountForm = false
				this.extManualMode = false
				this.extTestOk = false
				this.extTestError = ''
				this.extForm = { email: '', imap_host: '', imap_port: 993, imap_ssl: 'ssl', smtp_host: '', smtp_port: 465, smtp_ssl: 'ssl', username: '', password: '', provider: '' }
				await this.loadExternalAccounts()
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-external'))
				showSuccess(this.t('souvera_mail', 'External account added'))
			} catch (e) {
				console.error('External account add failed', e)
				const msg = e?.response?.data?.error || e?.response?.data?.message || e?.message || this.t('souvera_mail', 'Failed to add account')
				showError(msg)
			}
		},
		async removeExtAccount(id) {
			try {
				const { remove } = extAccountsApi
				await remove(id)
				this.extAccounts = this.extAccounts.filter(a => a.id !== id)
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-external'))
				showSuccess(this.t('souvera_mail', 'Account removed'))
			} catch (e) { showError(this.t('souvera_mail', 'Failed to remove account')) }
		},
		async testExtAccount(acct) {
			try {
				const { test } = extAccountsApi
				const r = await test(acct.id)
				if (r.ok) showSuccess(this.t('souvera_mail', 'Connection successful'))
				else showError(r.error || this.t('souvera_mail', 'Connection failed'))
			} catch { showError(this.t('souvera_mail', 'Test failed')) }
		},
		editSieve(filter) {
			this.editingSieve = filter
			this.showSieveEditor = true
		},
		closeSieveEditor() {
			this.showSieveEditor = false
			this.editingSieve = null
		},
		async toggleSieve(filter) {
			try {
				// Flipping a filter means adding/removing it from the
				// disabled list, then rebuilding the combined main script.
				const disabled = this.sieveScripts
					.filter(s => !s.isMain && !s.enabled)
					.map(s => s.name)
				const target = filter.enabled ? [...disabled, filter.name] : disabled.filter(n => n !== filter.name)
				await rebuild(target)
				await this.loadSieve()
				showSuccess(this.t('souvera_mail', 'Filters updated'))
			} catch (e) {
				showError(this.t('souvera_mail', 'Failed to update filter'))
			}
		},
		async deleteSieve(filter) {
			try {
				await deleteScript(filter.name)
				await rebuild()
				await this.loadSieve()
				showSuccess(this.t('souvera_mail', 'Filter deleted'))
			} catch (e) {
				showError(this.t('souvera_mail', 'Failed to delete filter'))
			}
		},
		formatSize(bytes) {
			if (!bytes) return '0 B'; const u = ['B','KB','MB','GB']; let i = 0, s = bytes
			while (s >= 1024 && i < u.length - 1) { s /= 1024; i++ }
			return Math.round(s * 10) / 10 + ' ' + u[i]
		},
		fmtDate(ts) { return ts ? new Date(ts).toLocaleDateString() : '' },
		/** Relatives "zuletzt benutzt" — fällt auf das Datum zurück. */
		fmtLastUsed(ts) {
			if (!ts) return this.t('souvera_mail', 'Never')
			const d = new Date(ts)
			if (Number.isNaN(d.getTime())) return this.t('souvera_mail', 'Never')
			const diffMs = Date.now() - d.getTime()
			if (diffMs < 60 * 1000) return this.t('souvera_mail', 'Just now')
			const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
			if (diffMs < 60 * 60 * 1000) return fmt.format(-Math.round(diffMs / (60 * 1000)), 'minute')
			if (diffMs < 24 * 60 * 60 * 1000) return fmt.format(-Math.round(diffMs / (60 * 60 * 1000)), 'hour')
			if (diffMs < 30 * 24 * 60 * 60 * 1000) return fmt.format(-Math.round(diffMs / (24 * 60 * 60 * 1000)), 'day')
			return new Date(ts).toLocaleDateString()
		},
		async create() {
			try {
				const r = await axios.post(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords'), { name: this.newName })
				this.passwords.push({ id: r.data.id, description: this.newName, createdAt: new Date().toISOString() })
				this.newSecret = r.data.secret
				this.showCreate = false; this.newName = ''
			} catch (e) { console.error('App password create failed', e); showError(this.t('souvera_mail', 'Failed to create app password')) }
		},
		async copySecret(secret) {
			try { await navigator.clipboard.writeText(secret); showSuccess(this.t('souvera_mail', 'Copied')) } catch {}
		},
		async remove(id) {
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/settings/app-passwords/' + id))
				this.passwords = this.passwords.filter(p => p.id !== id)
				showSuccess(this.t('souvera_mail', 'App password removed'))
			} catch (e) { console.error('App password remove failed', e); showError(this.t('souvera_mail', 'Failed to remove app password')) }
		},
		async setSharedPosition(above) {
			this.sharedAbove = above
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/shared/position'), { position: above ? 'above' : 'below' })
				showSuccess(this.t('souvera_mail', 'Shared folder position saved'))
			} catch (e) { console.error('Shared position save failed', e); showError(this.t('souvera_mail', 'Failed to save')) }
		},
		async loadVacationState() {
			try {
				const { data } = await axios.get(generateUrl('/apps/souvera_mail/vacation/state'))
				this.vacationState = data.state || this.vacationState
				if (data.status === 'error') {
					showError(data.message || this.t('souvera_mail', 'Failed to load vacation state'))
					this.vacationState = { ...this.vacationState, debug: (data.state && data.state.debug) || { stateError: data.message || 'unbekannt' } }
				}
			} catch (e) {
				console.error('Vacation state load failed', e)
				// Fallback: die ältere, seit jeher vorhandene /vacation-Route
				// liefert denselben Zustand (state-Feld), falls die Instanz die
				// /vacation/state-Route nicht registriert hat (404).
				if (e?.response?.status === 404) {
					try {
						const { data } = await axios.get(generateUrl('/apps/souvera_mail/vacation'))
						if (data.state) {
							this.vacationState = data.state
							if (data.state.debug && data.state.debug.stateError) {
								showError('Abwesenheit (Fallback): ' + data.state.debug.stateError)
							}
							return
						}
					} catch (e2) {
						console.error('Vacation fallback failed', e2)
					}
				}
				const status = e?.response?.status || 'netz'
				const detail = (typeof e?.response?.data === 'string' ? e.response.data : (e?.message || ''))
				showError('Abwesenheit: ' + status + ' — ' + String(detail).slice(0, 300))
			}
		},
		async onVacationSyncToggle(val) {
			this.vacationSync = !!val
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { vacationSync: this.vacationSync })
			} catch {}
			await this.syncVacationNow()
		},
		async syncVacationNow() {
			try {
				await axios.post(generateUrl('/apps/souvera_mail/vacation/sync'))
			} catch (e) {
				// Fallback: Sync über die seit jeher vorhandene /vacation-Route,
				// falls die Instanz /vacation/sync nicht registriert hat.
				if (e?.response?.status === 404) {
					try {
						await axios.post(generateUrl('/apps/souvera_mail/vacation'), { action: 'sync' })
					} catch {}
				}
			}
			await this.loadVacationState()
			showSuccess(this.t('souvera_mail', 'Synchronized'))
		},
		openAvailabilitySettings() {
			window.open(OC.generateUrl('/settings/user/availability'), '_blank')
		},
		toggleVacationEditor() {
			if (this.showVacationEditor) {
				this.showVacationEditor = false
				return
			}
			const st = this.vacationState
			this.vacationForm = {
				from: st.start ? new Date(st.start + 'T00:00:00').getTime() / 1000 : null,
				to: st.end ? new Date(st.end + 'T00:00:00').getTime() / 1000 : null,
				short: st.short || '',
				long: st.long || '',
			}
			this.showVacationEditor = true
		},
		async saveVacationToNextcloud() {
			if (!this.accountUid) { showError(this.t('souvera_mail', 'Cannot resolve user id')); return }
			const fmt = (ts) => {
				if (!ts) return ''
				const d = new Date(ts * 1000)
				return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
			}
			const firstDay = fmt(this.vacationForm.from)
			const lastDay = fmt(this.vacationForm.to)
			if (!firstDay || !lastDay) { showError(this.t('souvera_mail', 'From and until are required')); return }
			this.vacationSaving = true
			try {
				await axios.post(generateOcsUrl('apps/dav/api/v1/outOfOffice/{uid}', 2).replace('{uid}', encodeURIComponent(this.accountUid)), {
					firstDay, lastDay,
					status: this.vacationForm.short || '',
					message: this.vacationForm.long || '',
				}, { headers: { 'OCS-APIRequest': 'true' } })
				this.showVacationEditor = false
				await this.syncVacationNow()
				showSuccess(this.t('souvera_mail', 'Saved'))
			} catch (e) {
				console.error('Out-of-office write failed', e)
				showError(e?.response?.data?.ocs?.meta?.message || this.t('souvera_mail', 'Failed to save'))
			} finally {
				this.vacationSaving = false
			}
		},
		async clearVacationInNextcloud() {
			if (!this.accountUid) { showError(this.t('souvera_mail', 'Cannot resolve user id')); return }
			this.vacationSaving = true
			try {
				await axios.delete(generateOcsUrl('apps/dav/api/v1/outOfOffice/{uid}', 2).replace('{uid}', encodeURIComponent(this.accountUid)), { headers: { 'OCS-APIRequest': 'true' } })
				this.showVacationEditor = false
				await this.syncVacationNow()
				showSuccess(this.t('souvera_mail', 'Deleted'))
			} catch (e) {
				console.error('Out-of-office delete failed', e)
				showError(e?.response?.data?.ocs?.meta?.message || this.t('souvera_mail', 'Failed to delete'))
			} finally {
				this.vacationSaving = false
			}
		},
		async onRemoteImagesChange(opt) {
			if (!opt) return
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { remoteImages: opt.value })
				showSuccess(this.t('souvera_mail', 'Saved'))
			} catch (e) { showError(this.t('souvera_mail', 'Failed to save')) }
		},
		async onAutoRefreshChange(opt) {
			if (!opt) return
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { autoRefresh: opt.value })
				showSuccess(this.t('souvera_mail', 'Saved'))
			} catch (e) { showError(this.t('souvera_mail', 'Failed to save')) }
		},
		async setLayout(mode) {
			const prev = { verticalLayout: this.verticalLayout, listOnlyLayout: this.listOnlyLayout, focusLayout: this.focusLayout }
			this.verticalLayout = mode === 'vertical'
			this.listOnlyLayout = mode === 'list' || mode === 'focus'
			this.focusLayout = mode === 'focus'
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), {
					verticalLayout: this.verticalLayout,
					listOnlyLayout: this.listOnlyLayout,
					focusLayout: this.focusLayout,
				})
			} catch (e) {
				// Revert on failure — the user must not see the option jump
				// back after reload with the save silently lost.
				Object.assign(this, prev)
				showError(this.t('souvera_mail', 'Failed to save layout'))
				return
			}
			// No page reload: apply the new layout LIVE via the app shell,
			// so the change takes effect the moment the user returns to the
			// inbox (and the active state can never visually jump back).
			window.dispatchEvent(new CustomEvent('souvera-mail:layout-changed', {
				detail: {
					verticalLayout: this.verticalLayout,
					listOnlyLayout: this.listOnlyLayout,
					focusLayout: this.focusLayout,
				},
			}))
		},
		async onSoundChange(val) {
			if (val?.value) {
				try {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/settings/preferences'), { notificationSound: val.value })
					showSuccess(this.t('souvera_mail', 'Notification sound saved'))
				} catch (e) { console.error('Sound save failed', e); showError(this.t('souvera_mail', 'Failed to save')) }
			}
		},
		previewSound() {
			const sound = this.soundOption?.value
			if (!sound || sound === 'none') return
			this.playSound(sound)
		},
		playSound(sound) {
			if (sound === 'chime' || sound === 'bell') {
				try {
					const ctx = new (window.AudioContext || window.webkitAudioContext)()
					const gain = ctx.createGain()
					gain.connect(ctx.destination)
					gain.gain.value = 0.15
					if (sound === 'chime') {
						const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 880; o1.type = 'sine'; o1.start(); o1.stop(ctx.currentTime + 0.15)
						const o2 = ctx.createOscillator(); o2.connect(gain); o2.frequency.value = 1100; o2.type = 'sine'; o2.start(ctx.currentTime + 0.15); o2.stop(ctx.currentTime + 0.35)
					} else {
						const o1 = ctx.createOscillator(); o1.connect(gain); o1.frequency.value = 660; o1.type = 'triangle'; o1.start(); gain.gain.setTargetAtTime(0, ctx.currentTime + 0.3, 0.05); o1.stop(ctx.currentTime + 0.5)
					}
					setTimeout(() => { try { gain.disconnect(); ctx.close() } catch {} }, 1000)
				} catch (e) { console.error('Sound preview failed', e) }
			} else {
				try {
					const root = (window.OC && window.OC.getRootPath) ? window.OC.getRootPath() : ''
					const a = new Audio(root + '/apps/souvera_mail/sound/' + sound + '.mp3')
					a.volume = 0.4
					const playPromise = a.play()
					if (playPromise) playPromise.catch(() => {})
				} catch {}
			}
		},
		async createFolder() {
			const name = this.newFolderName.trim()
			if (!name) return
			try {
				const body = { name }
				if (this.newSubfolderParentId) body.parentId = this.newSubfolderParentId
				const { data } = await axios.post(generateUrl('/apps/souvera_mail/api/v2/mailboxes'), body)
				this.userFoldersList.push({ id: data.id, name, parentId: this.newSubfolderParentId || null })
				this.showCreateFolder = false; this.newFolderName = ''; this.newSubfolderParentId = null
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-mailboxes'))
				showSuccess(this.t('souvera_mail', 'Folder created'))
			} catch (e) { console.error('Folder create failed', e); showError(this.t('souvera_mail', 'Failed to create folder')) }
		},
		async startRenameFolder(f) {
			const name = prompt(this.t('souvera_mail', 'New name'), f.name)
			if (name && name.trim() && name.trim() !== f.name) {
				try {
					await axios.put(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + f.id), { name: name.trim() })
					f.name = name.trim()
					showSuccess(this.t('souvera_mail', 'Folder renamed'))
				} catch (e) { console.error('Folder rename failed', e); showError(this.t('souvera_mail', 'Failed to rename folder')) }
			}
		},
		async deleteFolder(id) {
			if (!confirm(this.t('souvera_mail', 'Delete this folder?'))) return
			try {
				await axios.delete(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + id))
				this.userFoldersList = this.userFoldersList.filter(f => f.id !== id)
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-mailboxes'))
				showSuccess(this.t('souvera_mail', 'Folder deleted'))
			} catch (e) { console.error('Folder delete failed', e); showError(this.t('souvera_mail', 'Failed to delete folder')) }
		},
		getFolderName(id) {
			const f = this.userFoldersList.find(x => x.id === id)
			return f ? f.name : ''
		},
		// ---- drag & drop to move folders ----
		onFolderDragStart(e, folder) {
			this.dragId = folder.id
			e.dataTransfer.effectAllowed = 'move'
			e.dataTransfer.setData('text/plain', folder.id)
		},
		onFolderDragOver(e, target) {
			if (this.dragId && this.dragId !== target.id) { this.dragOverId = target.id }
		},
		onFolderDragLeave() { this.dragOverId = null },
		async onFolderDrop(e, target) {
			e.preventDefault(); this.dragOverId = null
			const id = this.dragId; this.dragId = null
			if (!id || id === target.id) return
			const moved = this.userFoldersList.find(f => f.id === id)
			if (!moved) return
			try {
				await axios.put(generateUrl('/apps/souvera_mail/api/v2/mailboxes/' + id), { parentId: target.id })
				moved.parentId = target.id
				window.dispatchEvent(new CustomEvent('souvera-mail:refresh-mailboxes'))
				showSuccess(this.t('souvera_mail', 'Folder moved'))
			} catch (e) { console.error('Folder move failed', e); showError(this.t('souvera_mail', 'Failed to move folder')) }
		},
		onFolderDragEnd() { this.dragId = null; this.dragOverId = null },
		openMigration() {
			// The migration assistant (provider.tools IMAP import) is a
			// separate bundle; the event forces it open even when it was
			// previously dismissed.
			window.dispatchEvent(new CustomEvent('souvera-mail:open-migration'))
		},
		async resetMigration() {
			if (!confirm(this.t('souvera_mail', 'Reset migration state? This allows starting a new import.'))) return
			try {
				await axios.post(generateUrl('/apps/souvera_mail/migration/reset'))
				this.migrationCompleted = false
				showSuccess(this.t('souvera_mail', 'Migration state reset — you can start a new import'))
			} catch (e) {
				console.error('Migration reset failed', e)
				showError(this.t('souvera_mail', 'Failed to reset migration'))
			}
		},
	},
}
</script>

<style scoped>
.settings-view { padding: 30px 32px; height: 100%; overflow-y: auto; box-sizing: border-box; }
.settings-view__title { margin: 0 0 24px; font-size: 22px; font-weight: 700; }

/* Masonry layout: cards keep their natural content height — the next card
   flows directly below, no equal-height rows. Column width drives the
   responsive column count (same effect as auto-fill minmax(380px,1fr)). */
.settings-grid { columns: 380px; column-gap: 20px; }

.settings-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	overflow: hidden;
	break-inside: avoid;
	margin-bottom: 20px;
}
.settings-card__title {
	display: flex; align-items: center; gap: 8px;
	margin: 0; padding: 14px 20px;
	font-size: 15px; font-weight: 600;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}
.settings-card__body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }

.setting-row {
	display: flex; justify-content: space-between; align-items: center;
	gap: 16px;
}
.setting-label { font-size: 14px; font-weight: 500; }
.setting-value { font-size: 14px; color: var(--color-text-maxcontrast); }
.setting-select { min-width: 180px; }
.setting-row__sound { display: flex; align-items: center; gap: 6px; }

.layout-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.layout-option {
	flex: 1; cursor: pointer;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	text-align: center;
	transition: border-color 0.2s;
}
.layout-option:hover { border-color: var(--color-primary-element); }
.layout-option--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}
.layout-preview {
	height: 60px; margin-bottom: 8px;
	border-radius: var(--border-radius);
	overflow: hidden; display: flex;
}
.layout-preview--horizontal { flex-direction: row; }
.layout-preview--horizontal .layout-preview__sidebar { width: 35%; background: var(--color-background-dark); border-right: 2px solid var(--color-border); }
.layout-preview--horizontal .layout-preview__detail { flex: 1; background: var(--color-main-background); border: 1px solid var(--color-border); border-left: none; }

.layout-preview--vertical { flex-direction: column; }
.layout-preview--list .layout-preview__sidebar { flex: 1; background: var(--color-background-dark); }
.layout-preview--focus { position: relative; background: rgba(0, 0, 0, 0.35); }
.layout-preview--focus .layout-preview__sidebar { position: absolute; inset: 0; background: var(--color-background-dark); }
.layout-preview--focus .layout-preview__detail { position: absolute; left: 50%; top: 10%; transform: translateX(-50%); width: 55%; height: 80%; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 4px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25); }
.layout-preview--vertical .layout-preview__sidebar { height: 35%; background: var(--color-background-dark); border-bottom: 2px solid var(--color-border); }
.layout-preview--vertical .layout-preview__detail { flex: 1; background: var(--color-main-background); border: 1px solid var(--color-border); border-top: none; }

.layout-option__label { font-size: 12px; font-weight: 500; color: var(--color-text-maxcontrast); }
.layout-option--active .layout-option__label { color: var(--color-primary-element); font-weight: 600; }

.settings-muted { color: var(--color-text-maxcontrast); font-size: 12px; margin: 4px 0 0; }
.shared-position-row { display: flex; flex-direction: column; gap: 6px; }
.create-row { display: flex; align-items: center; gap: 8px; }
.create-row :deep(input) { min-width: 200px; }
.password-list { display: flex; flex-direction: column; gap: 6px; }
.migration-completed { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.migration-completed__badge { color: #2e7d32; font-weight: 600; font-size: 13px; }
.password-row {
	display: flex; justify-content: space-between; align-items: center; gap: 8px;
	padding: 10px 14px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}
.password-name { font-weight: 500; font-size: 13px; }
.password-secret { display: flex; align-items: center; gap: 4px; margin-top: 2px; }
.password-secret code { font-family: monospace; font-size: 12px; padding: 1px 6px; background: var(--color-background-dark); border-radius: 3px; cursor: pointer; user-select: all; }
.password-secret--hidden { letter-spacing: 2px; }
.password-value { font-size: 12px; font-family: monospace; word-break: break-all; }
.setting-row--column { flex-direction: column; align-items: stretch; }

.identity-list { display: flex; flex-direction: column; gap: 4px; margin-top: 4px; }
.identity-row { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-radius: 6px; background: var(--color-background-dark); }
.identity-row__default { flex-shrink: 0; }
.identity-row__star--active { color: var(--color-primary-element); fill: var(--color-primary-element); }
/* The active signature icon must stay visible in every theme — force the
   color on the SVG itself, otherwise NcButton's icon styles can override
   the class (white-on-white in light mode). */
.identity-row__sig--active,
.identity-row__sig--active :deep(svg) {
	color: var(--color-primary-element) !important;
	fill: var(--color-primary-element) !important;
}
.identity-row__info { flex: 1; min-width: 0; }
.identity-row__email { font-weight: 600; font-size: 13px; }
.identity-row__name { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--color-text-maxcontrast); margin-top: 2px; }
.identity-row__alias-tag { font-size: 10px; padding: 0 6px; border-radius: 3px; background: var(--color-primary-element); color: #fff; }
.identity-row__ext-tag { background: var(--color-background-darker); color: var(--color-text-maxcontrast); }
.identity-sig-dialog { display: flex; flex-direction: column; gap: 12px; min-width: 420px; max-width: 90vw; }
.identity-sig-dialog__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.identity-sig-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; }
.signature-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
	background: var(--color-main-background);
}
.signature-editor__actions {
	display: flex; gap: 8px; margin-top: 8px;
}
.hidden-file-input { display: none; }
.signature-textarea {
	width: 100%; border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 14px;
	font: inherit; font-size: 13px; resize: vertical;
	background: var(--color-main-background); color: var(--color-main-text);
	box-sizing: border-box;
}
/* After .signature-textarea so monospace wins for the source view */
.signature-textarea--source {
	min-height: 200px;
	font-family: monospace; font-size: 12px; font-weight: normal;
}
/* True layout-preserving preview — renders the EXACT sanitized HTML
   (no Tiptap normalisation, which would strip tables/images/layout). */
.signature-preview {
	min-height: 120px;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-size: 13px; line-height: 1.5;
	overflow-x: hidden;
	overflow-y: auto;
	word-break: break-word;
}
.signature-preview :deep(img) { max-width: 100%; height: auto; }
.folder-list { display: flex; flex-direction: column; gap: 4px; }
.folder-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius); }
.folder-row__actions { display: flex; gap: 2px; }
.folder-row__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; margin-right: 8px; }
.folder-row--dragging { opacity: 0.4; }
.folder-row--drag-over { border-color: var(--color-primary-element) !important; background: var(--color-primary-element-light); }
.create-row__sub { font-size: 12px; color: var(--color-text-maxcontrast); margin: 2px 0; }
.folder-heading { font-size: 11px; font-weight: 600; color: var(--color-text-maxcontrast); text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px 4px; }
.quota-bar { height: 6px; background: var(--color-border); border-radius: 3px; overflow: hidden; margin: 4px 0 8px; }
.quota-bar__fill { height: 100%; background: var(--color-primary-element); border-radius: 3px; transition: width 0.4s ease; }

.sieve-list { display: flex; flex-direction: column; gap: 2px; }
.sieve-list__item { display: flex; align-items: center; padding: 6px 8px; border-radius: 6px; gap: 8px; }
.sieve-list__item:hover { background: var(--color-background-hover); }
.sieve-list__name { font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sieve-list__active { font-size: 11px; padding: 0 6px; border-radius: 3px; background: #e8f5e9; color: #2e7d32; flex-shrink: 0; }
.sieve-list__actions { display: flex; gap: 2px; flex-shrink: 0; }
.sieve-list__add { margin-top: 8px; }

.password-reveal { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
.password-reveal code { font-family: monospace; font-size: 14px; padding: 6px 12px; background: var(--color-background-dark); border-radius: 6px; flex: 1; word-break: break-all; }

.ext-account-form { display: flex; flex-direction: column; gap: 12px; padding: 8px 0; }
.ext-account-form__field { display: flex; flex-direction: column; gap: 4px; }
.ext-account-form__field label { font-size: 13px; font-weight: 600; color: var(--color-text-maxcontrast); }
.ext-account-form__row { display: flex; gap: 12px; }
.ext-account-form__row .ext-account-form__field { flex: 1; }
.ext-account-form__actions { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.ext-account-form__buttons { display: flex; justify-content: flex-end; gap: 8px; }
.ext-account-form__test-result { font-size: 12px; min-height: 16px; }
.ext-account-form__test-result--ok { color: #2e7d32; font-weight: 600; }
.ext-account-form__test-result--err { color: #c62828; }
.ext-account-form__preset-summary {
	display: flex; flex-direction: column; gap: 4px;
	padding: 8px 12px; border-radius: 6px;
	background: var(--color-background-dark);
}
.ext-account-form__preset-row { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.ext-account-form__preset-label { width: 48px; font-weight: 600; color: var(--color-text-maxcontrast); flex-shrink: 0; }
.ext-account-form__preset-row code { font-family: monospace; font-size: 12px; }
.ext-account-form__section { display: flex; flex-direction: column; gap: 6px; padding-top: 4px; }
.ext-account-form__section-title { font-size: 13px; font-weight: 700; color: var(--color-main-text); }
.ext-account-form .native-select {
	width: 100%; min-height: 34px; padding: 4px 8px;
	border: 1px solid var(--color-border); border-radius: 6px;
	background: var(--color-main-background); color: var(--color-main-text);
	font-size: 13px;
}


.vacation-debug { margin-top: 8px; }
.vacation-debug summary { font-size: .8rem; color: var(--color-text-maxcontrast); cursor: pointer; }
.vacation-debug__body { font-size: .72rem; background: var(--color-background-dark); padding: 8px; border-radius: 8px; overflow-x: auto; max-height: 260px; }
.vacation-divider { border-top: 1px solid var(--color-border); margin: 14px 0; }
.vacation-status { font-size: 13px; color: var(--color-text-maxcontrast); }
.vacation-status--active { color: var(--color-success); font-weight: 600; }
.vacation-status--warn { color: var(--color-warning); }
.vacation-status__meta { font-weight: 400; }
.vacation-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.vacation-editor { display: flex; flex-direction: column; gap: 10px; }
.vacation-editor__row { display: flex; gap: 10px; flex-wrap: wrap; }
.vacation-editor__actions { display: flex; gap: 8px; justify-content: flex-end; }

@media (max-width: 768px) {
	.setting-row { flex-direction: column; align-items: stretch; gap: 4px; }
	.setting-row > div { width: 100%; }
	.setting-select { min-width: 0; width: 100%; }
	.layout-options { grid-template-columns: 1fr; }
	.vacation-editor__row { flex-direction: column; }
}

</style>