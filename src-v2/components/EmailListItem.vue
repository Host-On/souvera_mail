<template>
	<div class="email-list-item"
		draggable="true"
		@dragstart="onDragStart($event)"
		:class="{
			'email-list-item--unread': !email.isRead,
			'email-list-item--active': active,
			'email-list-item--checked': checked,
		}"
		@click="$emit('click')">
		<div class="email-list-item__avatar" @click.stop="$emit('check')">
			<SenderAvatar :email="email.fromAddress || ''" :name="email.fromName || email.fromAddress" :size="40" />
			<div class="avatar-check-overlay" :class="{ 'avatar-check-overlay--visible': checked }">
				<div class="checkbox-box" :class="{ 'checkbox-box--checked': checked }">
					<Check v-if="checked" :size="14" />
				</div>
			</div>
		</div>
		<div class="email-list-item__body">
			<div class="email-list-item__line1">
				<span class="email-list-item__sender">
					<span v-if="!email.isRead" class="unread-dot" />
					{{ email.fromName || email.fromAddress }}
				</span>
				<span class="email-list-item__date">
					<NcDateTime :timestamp="email.receivedAt ? new Date(email.receivedAt).getTime() : undefined" :relative="true" :weekday="false" />
				</span>
			</div>
			<div class="email-list-item__line2">
				<span class="email-list-item__subject">{{ email.subject || t('souvera_mail', '(no subject)') }}</span>
				<span class="email-list-item__icons">
					<Paperclip v-if="email.hasAttachment" :size="14" />
					<Star v-if="email.isFlagged" :size="14" class="email-list-item__flag" @click.stop="$emit('flag')" />
				</span>
			</div>
			<div v-if="email.preview" class="email-list-item__preview">{{ email.preview }}</div>
		</div>
	</div>
</template>

<script>
import { NcAvatar, NcDateTime } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Star from 'vue-material-design-icons/Star.vue'
import SenderAvatar from './SenderAvatar.vue'

export default {
	name: 'EmailListItem',
	components: { NcAvatar, NcDateTime, Check, Paperclip, Star, SenderAvatar },
	props: {
		email: { type: Object, required: true },
		active: { type: Boolean, default: false },
		checked: { type: Boolean, default: false },
	},
	emits: ['click', 'check', 'flag'],
	methods: {
		onDragStart(e) {
			e.dataTransfer.effectAllowed = 'move'
			e.dataTransfer.setData('text/plain', this.email.id)
			// Store in window for cross-component drag (list → sidebar)
			window.__souveraDragEmail = this.email.id
		},
	},
}
</script>

<style scoped>
.email-list-item {
	display: flex; align-items: flex-start; gap: 10px;
	padding: 8px 12px; cursor: pointer;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}
.email-list-item:hover { background: var(--color-background-hover); }
.email-list-item--checked { background: var(--color-primary-element-light); }
.email-list-item--unread { background: var(--color-primary-element-light); }
.email-list-item--active {
	background: var(--color-primary-element-light);
	box-shadow: inset 3px 0 0 var(--color-primary-element);
}
.email-list-item__avatar {
	position: relative; flex-shrink: 0; margin-top: 2px;
}
.avatar-check-overlay {
	position: absolute; top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0,0,0,0.35);
	border-radius: 50%;
	display: flex; align-items: center; justify-content: center;
	opacity: 0; transition: opacity 0.15s;
}
.email-list-item:hover .avatar-check-overlay { opacity: 1; }
.avatar-check-overlay--visible { opacity: 1; }
.checkbox-box {
	width: 18px; height: 18px;
	border: 2px solid #fff; border-radius: 3px;
	display: flex; align-items: center; justify-content: center;
	transition: all 0.15s;
}
.checkbox-box--checked {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-text);
}
.email-list-item__body { flex: 1; min-width: 0; overflow: hidden; }
.email-list-item__line1, .email-list-item__line2 {
	display: flex; justify-content: space-between; align-items: baseline;
}
.email-list-item__sender {
	flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
	font-size: 14px; display: flex; align-items: center; gap: 6px;
}
.unread-dot {
	width: 8px; height: 8px; border-radius: 50%;
	background: var(--color-primary-element); flex-shrink: 0;
}
.email-list-item--unread .email-list-item__sender { font-weight: 600; }
.email-list-item__date {
	font-size: 12px; color: var(--color-text-maxcontrast);
	flex-shrink: 0; margin-left: 8px;
}
.email-list-item__subject { font-size: 13px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.email-list-item__icons { display: flex; gap: 4px; flex-shrink: 0; margin-left: 4px; color: var(--color-text-maxcontrast); }
.email-list-item__flag { color: var(--color-warning); cursor: pointer; }
.email-list-item__preview {
	font-size: 12px; color: var(--color-text-maxcontrast);
	margin-top: 1px;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
</style>
