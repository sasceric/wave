<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { Heart, MoreVertical, Pencil, Reply, Trash2 } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import ActionMenu from '../shared/ActionMenu.vue'

const props = defineProps({
  message: { type: Object, required: true },
  userId: { type: Number, default: null },
  quoteLabel: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  canModify: { type: Boolean, default: false },
  mine: { type: Boolean, default: false },
})
const emit = defineEmits(['reply', 'heart', 'jump', 'edit', 'delete'])
const { t } = useI18n()
const actionMenu = ref(null)
const swipe = ref(0)
const liked = computed(() => (props.message.hearts || []).includes(props.userId))
const replyLabel = computed(() => props.message.replyTo?.senderId === props.userId
  ? t(props.mine ? 'chatInteractions.replyToSelf' : 'chatInteractions.replyToYou')
  : t(props.mine ? 'chatInteractions.yourReplyTo' : 'chatInteractions.replyTo', { name: props.quoteLabel }))
let pressTimer = 0
let gesture = null
let longPressOpened = false
function cancelPress() {
  window.clearTimeout(pressTimer)
  pressTimer = 0
}
function cancelGesture() {
  cancelPress()
  gesture = null
  swipe.value = 0
}
function startGesture(event) {
  longPressOpened = false
  if (props.disabled || props.message.deletedAt || event.button > 0 || event.isPrimary === false || event.target.closest('a, button')) return
  cancelGesture()
  gesture = { x: event.clientX, y: event.clientY, touch: event.pointerType !== 'mouse', swiping: false }
  if (gesture.touch) event.currentTarget.setPointerCapture?.(event.pointerId)
  pressTimer = window.setTimeout(() => { gesture = null; swipe.value = 0; longPressOpened = true; actionMenu.value?.openMenu() }, 500)
}
function moveGesture(event) {
  if (!gesture) return
  const dx = event.clientX - gesture.x
  const dy = event.clientY - gesture.y
  if (Math.abs(dy) > 12 && !gesture.swiping) { cancelGesture(); return }
  if (Math.abs(dx) > 10 || Math.abs(dy) > 10) cancelPress()
  if (gesture.touch && dx > 12 && Math.abs(dx) > Math.abs(dy) * 2) {
    gesture.swiping = true
    swipe.value = Math.min(dx, 80)
  }
}
function endGesture() {
  const shouldReply = swipe.value >= 60 && !props.disabled
  cancelGesture()
  if (shouldReply) emit('reply', props.message)
}
function openMenu(event) {
  if (props.disabled || props.message.deletedAt || event.target.closest('a')) return
  event.preventDefault()
  cancelGesture()
  actionMenu.value?.openMenu()
}
function reply() { emit('reply', props.message) }
function heart() { emit('heart', props.message) }
function handleClick(event) {
  if (!longPressOpened) return
  longPressOpened = false
  event.preventDefault()
  event.stopPropagation()
}
onBeforeUnmount(cancelGesture)
</script>

<template>
  <div class="chat-message-bubble" :class="{ 'is-swiping': swipe > 0, 'has-hearts': message.hearts?.length, 'is-mine': mine, 'has-reply': message.replyTo }" :style="swipe ? { transform: `translateX(${swipe}px)` } : undefined" @pointerdown="startGesture" @pointermove="moveGesture" @pointerup="endGesture" @pointercancel="cancelGesture" @lostpointercapture="cancelGesture" @contextmenu="openMenu" @click.capture="handleClick">
    <span v-if="swipe" class="chat-message-bubble__swipe" aria-hidden="true"><Reply :size="20" /></span>
    <div v-if="message.replyTo" class="chat-message-bubble__reply-context">
      <div class="chat-message-bubble__reply-label"><Reply :size="13" aria-hidden="true" />{{ replyLabel }}</div>
      <button type="button" class="chat-message-bubble__quote" :aria-label="t('chatInteractions.viewOriginal')" @click="emit('jump', message.replyTo.id)"><span>{{ message.replyTo.deletedAt ? t('chatInteractions.deleted') : message.replyTo.body }}</span></button>
    </div>
    <div class="chat-message-bubble__content campaign-messages__message" :class="{ 'campaign-messages__message--mine': mine }">
      <div v-if="!disabled && !message.deletedAt" class="chat-message-bubble__toolbar">
        <button type="button" class="chat-message-bubble__heart" :class="{ 'is-liked': liked }" :disabled="busy" :aria-label="t(liked ? 'chatInteractions.removeHeart' : 'chatInteractions.heart')" :aria-pressed="liked" @click="heart"><Heart :size="17" :fill="liked ? 'currentColor' : 'none'" aria-hidden="true" /></button>
        <ActionMenu ref="actionMenu" :label="t('chatInteractions.actions')" :restore-on-select="false">
          <template #trigger><MoreVertical :size="18" aria-hidden="true" /></template>
          <button type="button" role="menuitem" class="action-menu__item" @click="reply"><Reply :size="17" aria-hidden="true" />{{ t('chatInteractions.reply') }}</button>
          <button v-if="canModify" type="button" role="menuitem" class="action-menu__item" @click="emit('edit', message)"><Pencil :size="17" aria-hidden="true" />{{ t('chatInteractions.edit') }}</button>
          <button v-if="canModify" type="button" role="menuitem" class="action-menu__item action-menu__item--danger" @click="emit('delete', message)"><Trash2 :size="17" aria-hidden="true" />{{ t('chatInteractions.delete') }}</button>
        </ActionMenu>
      </div>
      <slot />
    </div>
    <slot name="preview" />
    <button v-if="message.hearts?.length && !message.deletedAt" type="button" class="chat-message-bubble__reaction" :disabled="disabled || busy" :aria-label="t(liked ? 'chatInteractions.removeHeart' : 'chatInteractions.heart')" :aria-pressed="liked" @click="heart"><Heart :size="16" fill="currentColor" aria-hidden="true" /><span v-if="message.hearts.length > 1">{{ message.hearts.length }}</span></button>
  </div>
</template>

<style scoped lang="scss" src="../../scss/components/messages/ChatMessageBubble.scss"></style>
