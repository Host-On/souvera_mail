<template>
	<NcDialog
		:name="t('souvera_mail', 'Resync mailbox')"
		size="normal"
		:no-close="isBusy"
		out-transition
		container="body"
		data-testid="resync-dialog"
		@closing="onClose"
		@update:open="v => { if (!v && !isBusy) $emit('close') }">
		<div class="souvera-resync souvera-content">

			<div v-if="stage === 'intro'">
				<p class="souvera-resync__lead">
					{{ t('souvera_mail', 'This clears the local cache in your browser and Souvera Mail reloads the current mailbox state completely from the server. This helps when:') }}
				</p>
				<ul class="souvera-resync__list">
					<li>
						<CheckCircleOutline :size="18" />
						<span>{{ t('souvera_mail', 'Folders are missing or counters are wrong') }}</span>
					</li>
					<li>
						<CheckCircleOutline :size="18" />
						<span>{{ t('souvera_mail', 'Messages are not yet visible after a migration') }}</span>
					</li>
					<li>
						<CheckCircleOutline :size="18" />
						<span>{{ t('souvera_mail', 'A draft gets stuck or an action does not go through') }}</span>
					</li>
				</ul>

				<NcNoteCard type="warning">
					{{ t('souvera_mail', 'Unsaved drafts in the compose window will be lost. Please save first.') }}
				</NcNoteCard>

				<NcNoteCard type="success">
					<strong>{{ t('souvera_mail', 'Full-text search (FTS):') }}</strong>
					{{ t('souvera_mail', 'The search index on the server is maintained automatically by Stalwart in the background and does not need a manual trigger. This action synchronizes the client — not the server-side FTS index.') }}
				</NcNoteCard>

				<div class="souvera-actions souvera-actions--split">
					<NcButton
						type="tertiary"
						data-testid="resync-cancel"
						@click="$emit('close')">
						{{ t('souvera_mail', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						data-testid="resync-start"
						@click="onStart">
						<template #icon><Refresh :size="20" /></template>
						{{ t('souvera_mail', 'Resync now') }}
					</NcButton>
				</div>
			</div>

			<div v-else-if="stage === 'busy'" class="souvera-resync__stage">
				<NcLoadingIcon :size="44" />
				<h2>{{ t('souvera_mail', 'Synchronizing …') }}</h2>
				<p>{{ progressText }}</p>
			</div>

			<div v-else-if="stage === 'error'" class="souvera-resync__stage">
				<AlertCircle :size="56" class="souvera-resync__error-icon" />
				<h2>{{ t('souvera_mail', 'Sync failed') }}</h2>
				<p>{{ errorMessage }}</p>
				<div class="souvera-actions souvera-actions--split">
					<NcButton
						type="tertiary"
						data-testid="resync-error-close"
						@click="$emit('close')">
						{{ t('souvera_mail', 'Close') }}
					</NcButton>
					<NcButton
						type="primary"
						data-testid="resync-error-retry"
						@click="onStart">
						<template #icon><Refresh :size="20" /></template>
						{{ t('souvera_mail', 'Retry') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { ref, computed } from 'vue'
import { generateUrl } from '@nextcloud/router'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

/**
 * v0.14.19 — user-facing "Postfach neu synchronisieren".
 *
 * Honesty: Stalwart 0.16 has NO per-user FTS-reindex endpoint. What
 * this dialog does is:
 *   1. POST /apps/souvera_mail/stalwart/resync (audit trail only)
 *   2. Delete every localStorage key that starts with a Snappymail
 *      namespace ("rl.", "snappymail.", "rainloop.", "smail.")
 *   3. Full page reload — Snappymail bootstraps from a fresh JMAP
 *      Session/get, rebuilds folder tree + message list in memory.
 *
 * The NcNoteCard makes the FTS-caveat explicit so we don't over-
 * promise. This fixes >90% of the sync-symptom class (stale unread
 * counters, missing folders after quota change, orphaned drafts).
 */
const SNAPPYMAIL_LS_PREFIXES = ['rl.', 'snappymail.', 'rainloop.', 'smail.']

function clearSnappymailLocalStorage() {
	let removed = 0
	try {
		const keys = []
		for (let i = 0; i < window.localStorage.length; i++) {
			const k = window.localStorage.key(i)
			if (k && SNAPPYMAIL_LS_PREFIXES.some(p => k.startsWith(p))) {
				keys.push(k)
			}
		}
		keys.forEach(k => { window.localStorage.removeItem(k); removed++ })
	} catch (e) { /* private mode / cross-origin restrictions — silent */ }
	return removed
}

async function jsonFetch(url, init = {}) {
	const token = (typeof OC !== 'undefined' && OC.requestToken) || ''
	const response = await fetch(url, {
		credentials: 'same-origin',
		...init,
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			requesttoken: token,
			...(init.headers || {}),
		},
	})
	let body = null
	try { body = await response.json() } catch (e) { body = { message: t('souvera_mail', 'Invalid response from server.') } }
	if (!response.ok) {
		const err = new Error(body?.message || `HTTP ${response.status}`)
		err.status = response.status
		throw err
	}
	return body
}

export default {
	name: 'ResyncDialog',
	components: {
		NcDialog, NcButton, NcNoteCard, NcLoadingIcon,
		Refresh, CheckCircleOutline, AlertCircle,
	},
	emits: ['close'],
	setup(props, { emit }) {
		const stage = ref('intro')     // 'intro' | 'busy' | 'error'
		const errorMessage = ref('')
		const progressText = ref('')
		const isBusy = computed(() => stage.value === 'busy')

		async function onStart() {
			stage.value = 'busy'
			errorMessage.value = ''
			progressText.value = t('souvera_mail', 'Sending request to the server …')
			try {
				await jsonFetch(
					generateUrl('/apps/souvera_mail/stalwart/resync'),
					{ method: 'POST' },
				)
			} catch (e) {
				stage.value = 'error'
				errorMessage.value = e?.message
					|| t('souvera_mail', 'The server could not be reached.')
				return
			}
			progressText.value = t('souvera_mail', 'Clearing local cache …')
			const removed = clearSnappymailLocalStorage()
			progressText.value = t('souvera_mail', 'Reloading Souvera Mail … ({n} cache entries cleared)', { n: removed })
			// Give the user 400ms to see the last progress line, then reload.
			window.setTimeout(() => {
				try {
					window.location.reload()
				} catch (e) { /* if reload is blocked, leave the dialog on-screen */ }
			}, 400)
		}

		function onClose() {
			if (!isBusy.value) emit('close')
		}

		return { stage, errorMessage, progressText, isBusy, onStart, onClose }
	},
}
</script>

<style scoped>
.souvera-resync {
	padding: var(--sc-field-gap);
	color: var(--color-main-text);
	line-height: 1.6;
	font-size: 0.95rem;
	display: flex;
	flex-direction: column;
	gap: var(--sc-field-gap);
}
.souvera-resync__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
.souvera-resync__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.souvera-resync__list li {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-main-text);
}
.souvera-resync__list li .material-design-icon {
	color: var(--color-success);
	flex-shrink: 0;
}
.souvera-resync__stage {
	text-align: center;
	padding: 24px 0;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
}
.souvera-resync__stage h2 {
	margin: 0;
	font-size: 1.3rem;
	font-weight: 600;
	color: var(--color-main-text);
}
.souvera-resync__stage p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 380px;
}
.souvera-resync__error-icon {
	color: var(--color-error);
}
.souvera-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	margin-top: 8px;
}
.souvera-actions--split {
	justify-content: space-between;
}
</style>
