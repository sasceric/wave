<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Bell, CalendarDays, Check, ChevronDown, Mail, MessageCircle, X } from '@lucide/vue'
import { I18nT, useI18n } from 'vue-i18n'
import LoadingSkeleton from './LoadingSkeleton.vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  loaded: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  mobile: { type: Boolean, default: false },
  error: { type: String, default: '' },
  unread: { type: Number, default: 0 },
  markingRead: { type: Boolean, default: false },
  enabling: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
  remaining: { type: Number, default: 0 },
})
const emit = defineEmits(['close', 'read-all', 'open', 'enable', 'load-more'])
const { t } = useI18n()
const panel = ref(null)
const heading = ref(null)
const failedImages = ref(new Set())
let previousOverflow = null
function lockScroll(mobile) {
  if (mobile && previousOverflow === null) {
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  } else if (!mobile && previousOverflow !== null) {
    document.body.style.overflow = previousOverflow
    previousOverflow = null
  }
}
watch(() => props.mobile, lockScroll)
onMounted(async () => { lockScroll(props.mobile); await nextTick(); heading.value?.focus() })
onBeforeUnmount(() => lockScroll(false))
function trapFocus(event) {
  if (!props.mobile || event.key !== 'Tab') return
  const buttons = panel.value?.querySelectorAll('button:not(:disabled)')
  if (!buttons?.length) return
  const first = buttons[0]
  const last = buttons[buttons.length - 1]
  if (event.shiftKey && (document.activeElement === first || document.activeElement === heading.value)) {
    event.preventDefault(); last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first.focus()
  }
}
function relativeTime(date) {
  const timestamp = new Date(date).getTime()
  if (!Number.isFinite(timestamp)) return ''
  const seconds = Math.max(0, (Date.now() - timestamp) / 1000)
  if (seconds < 60) return t('app.notificationTime.now')
  for (const [unit, size] of [['years', 31536000], ['months', 2592000], ['days', 86400], ['hours', 3600], ['minutes', 60]]) {
    if (seconds >= size) return t(`app.notificationTime.${unit}`, { count: Math.floor(seconds / size) })
  }
  return ''
}
</script>

<template>
  <section id="header-notifications-panel" ref="panel" class="notifications-panel" role="dialog" :aria-modal="mobile || undefined" aria-labelledby="notifications-panel-title" @keydown="trapFocus" @keydown.esc.stop.prevent="emit('close')">
    <header class="notifications-panel__heading">
      <h2 id="notifications-panel-title" ref="heading" tabindex="-1">{{ t('app.notifications') }}</h2>
      <button v-if="!disabled" class="notifications-panel__read" type="button" :aria-label="t('app.markAllRead')" :title="t('app.markAllRead')" :disabled="markingRead || !unread" @click="emit('read-all')"><span>{{ t('app.markAllRead') }}</span><Check :size="16" aria-hidden="true" /></button>
      <button class="notifications-panel__close" type="button" :aria-label="t('app.closeNotifications')" @click="emit('close')"><X :size="20" aria-hidden="true" /></button>
    </header>
    <p v-if="error" class="notifications-panel__error" role="alert">{{ error }}</p>
    <div v-if="disabled" class="notifications-panel__disabled">
      <div class="notifications-panel__sleep" aria-hidden="true"><Bell :size="48" stroke-width="1.5" /><span>z</span></div>
      <h3>{{ t('app.notificationsOff') }}</h3>
      <p>{{ t('app.notificationsOffHint') }}</p>
      <button class="button button--dark" type="button" :disabled="enabling" @click="emit('enable')"><Bell :size="20" aria-hidden="true" />{{ t('app.enableNotifications') }}</button>
      <ul class="notifications-panel__examples">
        <li><Mail :size="22" aria-hidden="true" />{{ t('app.notificationExampleOffers') }}</li>
        <li><MessageCircle :size="22" aria-hidden="true" />{{ t('app.notificationExampleMessages') }}</li>
        <li><CalendarDays :size="22" aria-hidden="true" />{{ t('app.notificationExampleDeadlines') }}</li>
      </ul>
    </div>
    <LoadingSkeleton v-else-if="!loaded && !error" :count="3" />
    <p v-else-if="!items.length && !error" class="notifications-panel__empty">{{ t('app.noNotifications') }}</p>
    <template v-else-if="items.length">
      <ol class="notifications-panel__list">
        <li v-for="notification in items" :key="notification.id">
          <button class="notifications-panel__item" :class="{ 'is-unread': !notification.readAt }" type="button" @click="emit('open', notification)">
            <span class="notifications-panel__image">
              <img v-if="notification.actorImageUrl && !failedImages.has(notification.id)" :src="notification.actorImageUrl" alt="" loading="lazy" @error="failedImages.add(notification.id)" />
              <span v-else aria-hidden="true">{{ (notification.actorName || 'W').charAt(0) }}</span>
            </span>
            <span class="notifications-panel__copy">
              <I18nT :keypath="`campaignChat.notificationTypes.${notification.type}`" tag="span"><template #actor><strong>{{ notification.actorName }}</strong></template><template #campaign><strong>{{ notification.campaign?.title || '' }}</strong></template></I18nT>
              <time :datetime="notification.createdAt">{{ relativeTime(notification.createdAt) }}</time>
            </span>
            <span v-if="!notification.readAt" class="notifications-panel__unread" :aria-label="t('app.unreadNotification')"></span>
          </button>
        </li>
      </ol>
      <button v-if="remaining > 0" class="notifications-panel__more" type="button" :disabled="loadingMore" @click="emit('load-more')">{{ t('app.loadMoreNotifications', { count: Math.min(5, remaining) }) }}<ChevronDown :size="21" aria-hidden="true" /></button>
    </template>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/NotificationsPanel.scss"></style>
