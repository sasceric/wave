<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ArrowRight, RefreshCw, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import LoadingSkeleton from './LoadingSkeleton.vue'
import { apiGet } from '../../lib/api'
import { createChatTimeFormatter } from '../../lib/chatTime'
import { groupInboxThreads } from '../../lib/inboxGroups'

const props = defineProps({ user: { type: Object, required: true } })
const emit = defineEmits(['close'])
const { t, locale } = useI18n()
const heading = ref(null)
const threads = ref([])
const loaded = ref(false)
const error = ref('')
const failedImages = ref(new Set())
const now = ref(Date.now())
const groups = computed(() => groupInboxThreads(threads.value, now.value))
const time = computed(() => createChatTimeFormatter(locale.value, t))
let requestVersion = 0
let unmounted = false

async function loadThreads() {
  const version = ++requestVersion
  const userId = props.user.id
  error.value = ''
  try {
    const response = await apiGet('/me/inbox?limit=5&filter=all', { locale: locale.value })
    if (unmounted || version !== requestVersion || props.user.id !== userId) return
    if (!Array.isArray(response.data)) throw new Error(t('api.invalidResponse'))
    threads.value = response.data.slice(0, 5)
    now.value = Date.now()
    loaded.value = true
  } catch (cause) {
    if (!unmounted && version === requestVersion && props.user.id === userId) error.value = cause.message
  }
}
function name(thread) {
  return (props.user.accountType === 'company' ? thread.creator?.displayName : thread.company?.name) || t('app.account')
}
function avatar(thread) {
  return props.user.accountType === 'company' ? thread.creator?.avatarUrl : thread.company?.logoUrl
}
function initials(thread) {
  return name(thread).trim().split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toLocaleUpperCase()
}
function destination(thread) {
  return { name: 'messages', query: { [thread.threadType === 'inquiry' ? 'inquiry' : 'conversation']: thread.id } }
}
function activityLabel(thread, group) {
  return group === 'today' ? time.value.clock(thread.lastMessageAt)
    : group === 'yesterday' ? t('campaignChat.yesterday') : time.value.full(thread.lastMessageAt).split(' ')[0]
}
function refreshFromRealtime(event) {
  const update = event.detail
  if (update?.type === 'chat_message' || update?.type === 'chat_read' || update?.notificationType === 'chat_message') void loadThreads()
}
watch(() => [props.user.id, locale.value], () => {
  threads.value = []
  loaded.value = false
  failedImages.value = new Set()
  void loadThreads()
})
onMounted(async () => {
  window.addEventListener('wave:inbox-refresh', loadThreads)
  window.addEventListener('wave:conversations-updated', loadThreads)
  window.addEventListener('wave:realtime', refreshFromRealtime)
  void loadThreads()
  await nextTick()
  heading.value?.focus()
})
onBeforeUnmount(() => {
  unmounted = true
  requestVersion += 1
  window.removeEventListener('wave:inbox-refresh', loadThreads)
  window.removeEventListener('wave:conversations-updated', loadThreads)
  window.removeEventListener('wave:realtime', refreshFromRealtime)
})
</script>

<template>
  <section id="header-messages-panel" class="messages-panel" role="dialog" aria-labelledby="messages-panel-title" @keydown.esc.stop.prevent="emit('close')">
    <header class="messages-panel__heading">
      <h2 id="messages-panel-title" ref="heading" tabindex="-1">{{ t('app.messages') }}</h2>
      <button type="button" :aria-label="t('app.closeMessages')" @click="emit('close')"><X :size="18" aria-hidden="true" /></button>
    </header>
    <div v-if="error" class="messages-panel__error" role="alert">
      <span>{{ error }}</span>
      <button type="button" :aria-label="t('campaignChat.retry')" @click="loadThreads"><RefreshCw :size="16" aria-hidden="true" /></button>
    </div>
    <LoadingSkeleton v-if="!loaded && !error" variant="rows" :count="3" />
    <p v-else-if="loaded && !threads.length" class="messages-panel__empty">{{ t('campaignChat.noConversations') }}</p>
    <div class="messages-panel__groups">
      <section v-for="group in groups" :key="group.key" class="messages-panel__group" :aria-label="t(`app.inboxGroups.${group.key}`)">
        <h3>{{ t(`app.inboxGroups.${group.key}`) }}</h3>
        <LocalizedLink v-for="thread in group.threads" :key="thread.threadKey" class="messages-panel__item" :class="{ 'is-unread': thread.unreadCount > 0 }" :to="destination(thread)" @click="emit('close')">
          <span class="messages-panel__avatar">
            <img v-if="avatar(thread) && !failedImages.has(thread.threadKey)" :src="avatar(thread)" alt="" width="36" height="36" loading="lazy" @error="failedImages.add(thread.threadKey)" />
            <span v-else aria-hidden="true">{{ initials(thread) }}</span>
          </span>
          <span class="messages-panel__copy">
            <strong>{{ name(thread) }}</strong>
            <span class="messages-panel__context">{{ thread.threadType === 'inquiry' ? t('campaignChat.directInquiry') : thread.campaign?.title }}</span>
            <span class="messages-panel__preview">{{ thread.lastMessage || t('campaignChat.selectConversation') }}</span>
          </span>
          <span class="messages-panel__meta">
            <time :datetime="thread.lastMessageAt" :title="time.full(thread.lastMessageAt)">{{ activityLabel(thread, group.key) }}</time>
            <span v-if="thread.unreadCount" class="messages-panel__unread" :aria-label="t('app.unreadMessages', { count: thread.unreadCount })">{{ thread.unreadCount > 99 ? '99+' : thread.unreadCount }}</span>
          </span>
        </LocalizedLink>
      </section>
    </div>
    <LocalizedLink class="messages-panel__all" :to="{ name: 'messages', query: {} }" @click="emit('close')">{{ t('app.showAllMessages') }}<ArrowRight :size="18" aria-hidden="true" /></LocalizedLink>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/MessagesPanel.scss"></style>
