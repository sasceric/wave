<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowRight,
  Building2,
  CalendarDays,
  Check,
  ChevronLeft,
  FileText,
  MapPin,
  Megaphone,
  MessageCircle,
  Plus,
  RefreshCw,
  Search,
  Send,
  Wallet,
} from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { localizedRouteName } from '../routePaths'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const conversations = ref([])
const inquiries = ref([])
const conversationMessages = ref([])
const pendingDetails = ref(null)
const selectedConversationId = ref(null)
const selectedInquiryId = ref(null)
const detailsOpen = ref(false)
const isMobileView = ref(false)
const visualViewportHeight = ref(0)
const visualViewportTop = ref(0)
const conversationQuery = ref('')
const conversationFilter = ref('all')
const draft = ref('')
const loading = ref(true)
const sending = ref(false)
const error = ref('')
const authRequired = ref(false)
const messageList = ref(null)
let messageScrollFrame = 0
let inboxRefreshInProgress = false

const pendingStart = computed(() => {
  const campaign = route.query.campaign
  const creatorId = Number(route.query.creatorId)
  const creatorSlug = route.query.creatorSlug
  if (
    typeof campaign !== 'string'
    || !campaign
    || !Number.isInteger(creatorId)
    || creatorId < 1
    || typeof creatorSlug !== 'string'
    || !creatorSlug
  ) {
    return null
  }

  return { campaign, creatorId, creatorSlug }
})

const selectedConversation = computed(() => conversations.value.find(
  (conversation) => conversation.id === selectedConversationId.value,
) || null)
const selectedInquiry = computed(() => inquiries.value.find(
  (inquiry) => inquiry.id === selectedInquiryId.value,
) || null)
const selectedThread = computed(() => selectedConversation.value || selectedInquiry.value)
const selectedThreadKey = computed(() => (
  selectedConversation.value
    ? `campaign-${selectedConversation.value.id}`
    : selectedInquiry.value
      ? `inquiry-${selectedInquiry.value.id}`
      : null
))
const mobileThreadOpen = computed(() => Boolean(
  selectedThread.value || (pendingStart.value && pendingDetails.value),
))
const viewportStyle = computed(() => ({
  '--chat-viewport-height': `${visualViewportHeight.value}px`,
  '--chat-viewport-top': `${visualViewportTop.value}px`,
}))

const threads = computed(() => [
  ...conversations.value.map((conversation) => ({
    ...conversation,
    threadType: 'campaign',
    threadKey: `campaign-${conversation.id}`,
  })),
  ...inquiries.value
    .filter((inquiry) => inquiry.canChat)
    .map((inquiry) => ({
      ...inquiry,
      threadType: 'inquiry',
      threadKey: `inquiry-${inquiry.id}`,
      campaign: { title: inquiry.packageTitle || t('campaignChat.directInquiry') },
      lastMessage: inquiry.lastMessage || inquiry.message,
      lastMessageAt: inquiry.lastMessageAt || inquiry.createdAt,
      unreadCount: 0,
    })),
].sort((first, second) => new Date(second.lastMessageAt) - new Date(first.lastMessageAt)))

const filteredConversations = computed(() => {
  const query = conversationQuery.value.trim().toLocaleLowerCase()
  return threads.value.filter((conversation) => {
    if (conversationFilter.value === 'unread' && !conversation.unreadCount) return false
    if (!query) return true

    return `${conversationName(conversation)} ${conversation.campaign.title} ${conversation.lastMessage || ''}`
      .toLocaleLowerCase()
      .includes(query)
  })
})

const newConversationRoute = computed(() => (
  currentUser.value?.accountType === 'company' ? 'account-applications' : 'campaigns'
))
const newConversationLabel = computed(() => t(
  currentUser.value?.accountType === 'company'
    ? 'campaignChat.newMessageCompany'
    : 'campaignChat.newMessageCreator',
))
const selectedCampaign = computed(() => (
  selectedConversation.value?.campaign || pendingDetails.value?.campaign || null
))
const selectedCompany = computed(() => (
  selectedConversation.value?.company
  || selectedInquiry.value?.company
  || selectedCampaign.value?.company
  || null
))
const selectedCreator = computed(() => (
  selectedConversation.value?.creator
  || selectedInquiry.value?.creator
  || pendingDetails.value?.creator
  || null
))
const counterpartName = computed(() => (
  selectedThread.value
    ? conversationName(selectedThread.value)
    : selectedCreator.value?.displayName || ''
))
const counterpartAvatar = computed(() => (
  currentUser.value?.accountType === 'company'
    ? selectedCreator.value?.avatarUrl || null
    : selectedCompany.value?.logoUrl || null
))
const currentAvatar = computed(() => (
  currentUser.value?.profile?.avatarUrl
  || currentUser.value?.profile?.logoUrl
  || null
))
const currentDisplayName = computed(() => (
  currentUser.value?.profile?.displayName
  || currentUser.value?.profile?.name
  || currentUser.value?.email
  || ''
))
const canSend = computed(() => (
  !sending.value
  && draft.value.trim().length > 0
  && draft.value.trim().length <= 2000
  && (selectedThread.value || (
    pendingStart.value
    && currentUser.value?.accountType === 'company'
    && pendingDetails.value
  ))
))

watch([isMobileView, mobileThreadOpen], ([mobile, threadOpen]) => {
  document.documentElement.classList.toggle('wave-chat-open', mobile && threadOpen)
  if (!mobile) detailsOpen.value = false
})

watch(
  [
    () => conversationMessages.value.length,
    selectedConversationId,
    selectedInquiryId,
    mobileThreadOpen,
    visualViewportHeight,
  ],
  scrollToLatestMessage,
  { flush: 'post' },
)

onMounted(() => {
  updateViewport()
  window.addEventListener('resize', updateViewport)
  window.visualViewport?.addEventListener('resize', updateViewport)
  window.visualViewport?.addEventListener('scroll', updateViewport)
  void loadInbox()
  window.addEventListener('wave:realtime', handleRealtimeUpdate)
  window.addEventListener('focus', refreshInboxWhenVisible)
  document.addEventListener('visibilitychange', refreshInboxWhenVisible)
})

onBeforeUnmount(() => {
  document.documentElement.classList.remove('wave-chat-open')
  window.removeEventListener('resize', updateViewport)
  window.visualViewport?.removeEventListener('resize', updateViewport)
  window.visualViewport?.removeEventListener('scroll', updateViewport)
  window.removeEventListener('wave:realtime', handleRealtimeUpdate)
  window.removeEventListener('focus', refreshInboxWhenVisible)
  document.removeEventListener('visibilitychange', refreshInboxWhenVisible)
  if (messageScrollFrame) {
    window.cancelAnimationFrame(messageScrollFrame)
  }
})

function updateViewport() {
  isMobileView.value = window.matchMedia('(max-width: 760px)').matches
  const viewport = window.visualViewport
  visualViewportHeight.value = Math.round(viewport?.height || window.innerHeight)
  visualViewportTop.value = Math.round(viewport?.offsetTop || 0)
}

function handleRealtimeUpdate(event) {
  const update = event.detail
  const conversationId = Number(update?.conversationId)
  const message = update?.message
  if (
    update?.notificationType !== 'chat_message'
    || !Number.isInteger(conversationId)
    || !message
    || typeof message !== 'object'
  ) {
    void refreshInbox()
    return
  }

  const conversation = conversations.value.find((item) => item.id === conversationId)
  if (!conversation) {
    void refreshInbox()
    return
  }

  const isIncoming = message.senderId !== currentUser.value?.id
  const updatedConversation = {
    ...conversation,
    lastMessage: message.body,
    lastMessageAt: message.createdAt,
    lastMessageSenderId: message.senderId,
    unreadCount: conversation.unreadCount + (isIncoming ? 1 : 0),
  }
  upsertConversation(updatedConversation)

  if (selectedConversationId.value === conversationId) {
    if (!conversationMessages.value.some((existing) => existing.id === message.id)) {
      conversationMessages.value.push(message)
    }
    void loadMessages({ ...updatedConversation, threadType: 'campaign' })
  } else if (isIncoming) {
    window.dispatchEvent(new Event('wave:conversations-updated'))
  }
}

function refreshInboxWhenVisible() {
  if (document.visibilityState === 'visible' && currentUser.value) {
    void refreshInbox()
  }
}

async function loadInbox() {
  loading.value = true
  error.value = ''
  authRequired.value = false
  try {
    const [conversationResponse, inquiryResponse] = await Promise.all([
      apiGet('/me/conversations'),
      apiGet('/me/inquiries'),
    ])
    conversations.value = conversationResponse.data
    inquiries.value = inquiryResponse.data.filter((inquiry) => inquiry.canChat)

    const requestedId = Number(route.query.conversation)
    const requestedConversation = conversations.value.find((item) => item.id === requestedId)
    if (requestedConversation) {
      await selectConversation({
        ...requestedConversation,
        threadType: 'campaign',
        threadKey: `campaign-${requestedConversation.id}`,
      }, false)
      return
    }

    const requestedInquiryId = Number(route.query.inquiry)
    const requestedInquiry = inquiries.value.find((item) => item.id === requestedInquiryId)
    if (requestedInquiry) {
      await selectConversation({
        ...requestedInquiry,
        threadType: 'inquiry',
        threadKey: `inquiry-${requestedInquiry.id}`,
      }, false)
      return
    }

    if (pendingStart.value) {
      const existing = conversations.value.find((item) => (
        item.campaign.slug === pendingStart.value.campaign
        && item.creator.id === pendingStart.value.creatorId
      ))
      if (existing) {
        await selectConversation({
          ...existing,
          threadType: 'campaign',
          threadKey: `campaign-${existing.id}`,
        })
      } else if (currentUser.value?.accountType === 'company') {
        await loadPendingDetails()
      }
      return
    }

    if (threads.value.length && !isMobileView.value) {
      await selectConversation(threads.value[0])
    } else {
      selectedConversationId.value = null
      selectedInquiryId.value = null
      conversationMessages.value = []
    }
  } catch (cause) {
    handleLoadError(cause)
  } finally {
    loading.value = false
  }
}

async function loadPendingDetails() {
  try {
    const target = pendingStart.value
    const [campaignResponse, creatorResponse] = await Promise.all([
      apiGet(`/campaigns/${encodeURIComponent(target.campaign)}`),
      apiGet(`/creators/${encodeURIComponent(target.creatorSlug)}`),
    ])
    pendingDetails.value = {
      campaign: campaignResponse.data,
      creator: creatorResponse.data,
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function selectConversation(thread, syncRoute = true) {
  selectedConversationId.value = thread.threadType === 'campaign' ? thread.id : null
  selectedInquiryId.value = thread.threadType === 'inquiry' ? thread.id : null
  pendingDetails.value = null
  detailsOpen.value = false
  if (syncRoute) {
    const query = thread.threadType === 'inquiry'
      ? { inquiry: thread.id }
      : { conversation: thread.id }
    await router.replace({
      name: localizedRouteName('messages', locale.value),
      query,
    })
  }
  await loadMessages(thread, true)
}

function backToInbox() {
  detailsOpen.value = false
  selectedConversationId.value = null
  selectedInquiryId.value = null
  pendingDetails.value = null
  conversationMessages.value = []
  void router.replace({
    name: localizedRouteName('messages', locale.value),
    query: {},
  })
}

async function loadMessages(thread, forceScroll = false) {
  try {
    const previousLatestMessageId = conversationMessages.value.at(-1)?.id
    if (thread.threadType === 'inquiry') {
      const response = await apiGet(`/me/inquiries/${thread.id}/messages`)
      conversationMessages.value = response.data
      error.value = ''
      if (forceScroll || response.data.at(-1)?.id !== previousLatestMessageId) {
        await scrollToLatestMessage()
      }
      return
    }

    const previousUnreadCount = conversations.value.find(
      (conversation) => conversation.id === thread.id,
    )?.unreadCount || 0
    const response = await apiGet(`/me/conversations/${thread.id}/messages`)
    conversationMessages.value = response.data
    upsertConversation(response.conversation)
    if (previousUnreadCount > 0 && response.conversation.unreadCount === 0) {
      window.dispatchEvent(new Event('wave:conversations-updated'))
    }
    error.value = ''
    if (forceScroll || response.data.at(-1)?.id !== previousLatestMessageId) {
      await scrollToLatestMessage()
    }
  } catch (cause) {
    handleLoadError(cause)
  }
}

async function scrollToLatestMessage() {
  await nextTick()
  if (messageScrollFrame) {
    window.cancelAnimationFrame(messageScrollFrame)
  }
  messageScrollFrame = window.requestAnimationFrame(() => {
    messageScrollFrame = 0
    const timeline = messageList.value
    if (timeline) {
      timeline.scrollTop = timeline.scrollHeight
    }
  })
}

async function refreshInbox() {
  if (inboxRefreshInProgress) {
    return
  }
  inboxRefreshInProgress = true
  try {
    const [conversationResponse, inquiryResponse] = await Promise.all([
      apiGet('/me/conversations'),
      apiGet('/me/inquiries'),
    ])
    conversations.value = conversationResponse.data
    inquiries.value = inquiryResponse.data.filter((inquiry) => inquiry.canChat)
    error.value = ''
    if (selectedThread.value) {
      await loadMessages({
        ...selectedThread.value,
        threadType: selectedInquiry.value ? 'inquiry' : 'campaign',
      })
    }
  } catch (cause) {
    if (cause.status !== 401) {
      error.value = cause.message
    }
  } finally {
    inboxRefreshInProgress = false
  }
}

function upsertConversation(conversation) {
  const existingIndex = conversations.value.findIndex((item) => item.id === conversation.id)
  if (existingIndex === -1) {
    conversations.value.unshift(conversation)
    return
  }
  conversations.value.splice(existingIndex, 1, conversation)
}

async function sendMessage() {
  if (!canSend.value) {
    return
  }
  sending.value = true
  error.value = ''
  const body = draft.value.trim()
  try {
    if (pendingStart.value && !selectedConversation.value) {
      const target = pendingStart.value
      const response = await apiRequest(
        `/company/campaigns/${encodeURIComponent(target.campaign)}/conversations`,
        {
          method: 'POST',
          body: { creatorId: target.creatorId, message: body },
        },
      )
      upsertConversation(response.data)
      conversationMessages.value = [response.message]
      selectedConversationId.value = response.data.id
      pendingDetails.value = null
      await router.replace({
        name: localizedRouteName('messages', locale.value),
        query: { conversation: response.data.id },
      })
      await scrollToLatestMessage()
    } else if (selectedInquiryId.value !== null) {
      const response = await apiRequest(
        `/me/inquiries/${selectedInquiryId.value}/messages`,
        { method: 'POST', body: { body } },
      )
      conversationMessages.value.push(response.data)
      const inquiry = inquiries.value.find((item) => item.id === selectedInquiryId.value)
      if (inquiry) {
        inquiry.lastMessage = body
        inquiry.lastMessageAt = response.data.createdAt
      }
      await scrollToLatestMessage()
    } else if (selectedConversationId.value !== null) {
      const response = await apiRequest(
        `/me/conversations/${selectedConversationId.value}/messages`,
        { method: 'POST', body: { body } },
      )
      conversationMessages.value.push(response.data)
      const conversation = selectedConversation.value
      if (conversation) {
        upsertConversation({
          ...conversation,
          lastMessage: body,
          lastMessageAt: response.data.createdAt,
          lastMessageSenderId: currentUser.value?.id,
          unreadCount: 0,
        })
      }
      await scrollToLatestMessage()
    }
    draft.value = ''
  } catch (cause) {
    error.value = cause.message
  } finally {
    sending.value = false
  }
}

function handleLoadError(cause) {
  if (cause.status === 401) {
    authRequired.value = true
    conversations.value = []
    inquiries.value = []
    selectedConversationId.value = null
    selectedInquiryId.value = null
    conversationMessages.value = []
    return
  }
  error.value = cause.message
}

function isMessageMine(message) {
  return selectedInquiry.value
    ? message.senderRole === currentUser.value?.accountType
    : message.senderId === currentUser.value?.id
}

function conversationName(conversation) {
  return currentUser.value?.accountType === 'company'
    ? conversation.creator.displayName
    : conversation.company.name
}

function conversationAvatar(conversation) {
  return currentUser.value?.accountType === 'company'
    ? conversation.creator.avatarUrl
    : conversation.company.logoUrl
}

function initials(name) {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join('')
    .toLocaleUpperCase()
}

function messageTime(value) {
  const intlLocale = locale.value === 'sr'
    ? 'sr-Latn'
    : locale.value === 'cnr'
      ? 'sr-Latn-ME'
      : locale.value

  const time = new Intl.DateTimeFormat(intlLocale, {
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))

  return `${formatDate(value)} ${time}`
}

function conversationTime(value) {
  if (!value) return ''
  const date = new Date(value)
  const today = new Date()
  const isToday = date.getFullYear() === today.getFullYear()
    && date.getMonth() === today.getMonth()
    && date.getDate() === today.getDate()
  const intlLocale = locale.value === 'sr'
    ? 'sr-Latn'
    : locale.value === 'cnr'
      ? 'sr-Latn-ME'
      : locale.value

  return isToday
    ? new Intl.DateTimeFormat(intlLocale, { hour: 'numeric', minute: '2-digit' }).format(date)
    : formatDate(value)
}
</script>

<template>
  <section
    class="campaign-messages campaign-messages--workspace"
    :style="viewportStyle"
  >
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <div v-if="authRequired" class="campaign-messages__signed-out">
      <p>{{ t('campaignChat.loginRequired') }}</p>
      <LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">
        {{ t('auth.signIn') }}
      </LocalizedLink>
    </div>
    <StatusMessage v-else-if="loading" variant="loading">{{ t('campaignChat.loading') }}</StatusMessage>
    <div
      v-else
      class="campaign-messages__layout"
      :class="{
        'has-active-chat': mobileThreadOpen,
        'is-details-open': detailsOpen,
      }"
    >
      <aside class="campaign-messages__inbox">
        <header class="campaign-messages__inbox-heading">
          <h1>{{ t('campaignChat.title') }}</h1>
          <div class="campaign-messages__inbox-actions">
            <LocalizedLink class="campaign-messages__new-message" :to="{ name: newConversationRoute }">
              <Plus :size="16" stroke-width="2" aria-hidden="true" />
              <span>{{ newConversationLabel }}</span>
            </LocalizedLink>
            <button
              class="campaign-messages__refresh"
              type="button"
              :aria-label="t('campaignChat.refresh')"
              :title="t('campaignChat.refresh')"
              @click="refreshInbox"
            >
              <RefreshCw :size="17" stroke-width="1.8" aria-hidden="true" />
            </button>
          </div>
        </header>

        <label class="campaign-messages__search">
          <span class="sr-only">{{ t('campaignChat.searchPlaceholder') }}</span>
          <Search :size="18" stroke-width="1.7" aria-hidden="true" />
          <input v-model="conversationQuery" type="search" :placeholder="t('campaignChat.searchPlaceholder')">
        </label>

        <div class="campaign-messages__filters" role="tablist" :aria-label="t('campaignChat.inbox')">
          <button
            type="button"
            role="tab"
            :aria-selected="conversationFilter === 'all'"
            :class="{ 'is-active': conversationFilter === 'all' }"
            @click="conversationFilter = 'all'"
          >{{ t('campaignChat.filterAll') }}</button>
          <button
            type="button"
            role="tab"
            :aria-selected="conversationFilter === 'unread'"
            :class="{ 'is-active': conversationFilter === 'unread' }"
            @click="conversationFilter = 'unread'"
          >{{ t('campaignChat.filterUnread') }}</button>
        </div>

        <div v-if="!conversations.length && !pendingStart" class="campaign-messages__conversation-list">
          <StatusMessage variant="empty">{{ t('campaignChat.noConversations') }}</StatusMessage>
        </div>
        <div v-else class="campaign-messages__conversation-list">
          <p v-if="conversations.length && !filteredConversations.length" class="campaign-messages__no-results">
            {{ t('campaignChat.noSearchResults') }}
          </p>
          <button
            v-for="conversation in filteredConversations"
            :key="conversation.threadKey"
            class="campaign-messages__conversation"
            :class="{ 'is-active': conversation.threadKey === selectedThreadKey }"
            type="button"
            @click="selectConversation(conversation)"
          >
            <span class="campaign-messages__avatar">
              <img
                v-if="conversationAvatar(conversation)"
                :src="conversationAvatar(conversation)"
                alt=""
                loading="lazy"
              >
              <span v-else>{{ initials(conversationName(conversation)) }}</span>
            </span>
            <span class="campaign-messages__conversation-body">
              <span class="campaign-messages__conversation-top">
                <strong>{{ conversationName(conversation) }}</strong>
                <time :datetime="conversation.lastMessageAt">{{ conversationTime(conversation.lastMessageAt) }}</time>
              </span>
              <span class="campaign-messages__conversation-meta">
                <span class="campaign-messages__campaign">
                  {{ conversation.threadType === 'inquiry'
                    ? t('campaignChat.directInquiry')
                    : conversation.campaign.title }}
                </span>
                <span v-if="conversation.unreadCount" class="campaign-messages__unread">
                  {{ conversation.unreadCount }}
                </span>
              </span>
              <span class="campaign-messages__preview">
                {{ conversation.lastMessage || t('campaignChat.selectConversation') }}
              </span>
            </span>
          </button>
        </div>
      </aside>

      <section class="campaign-messages__thread" aria-live="polite">
        <template v-if="selectedThread">
          <header class="campaign-messages__thread-heading">
            <button
              class="campaign-messages__back"
              type="button"
              :aria-label="t('campaignChat.backToInbox')"
              @click="backToInbox"
            >
              <ChevronLeft :size="22" aria-hidden="true" />
            </button>
            <span class="campaign-messages__avatar campaign-messages__avatar--large">
              <img v-if="counterpartAvatar" :src="counterpartAvatar" alt="" loading="lazy">
              <span v-else>{{ initials(counterpartName) }}</span>
            </span>
            <div class="campaign-messages__thread-person">
              <h2>{{ counterpartName }}</h2>
              <LocalizedLink
                v-if="selectedConversation"
                :to="{ name: 'campaign-detail', params: { slug: selectedConversation.campaign.slug } }"
              >
                {{ selectedConversation.campaign.title }}
              </LocalizedLink>
              <p v-else>{{ selectedInquiry.packageTitle || t('campaignChat.directInquiry') }}</p>
            </div>
            <span class="campaign-messages__thread-kind">
              {{ t(selectedInquiry ? 'campaignChat.directInquiry' : 'campaignChat.campaign') }}
            </span>
            <button
              class="campaign-messages__details-toggle"
              type="button"
              :aria-label="t('campaignChat.showDetails')"
              :aria-expanded="detailsOpen"
              aria-controls="campaign-details-panel"
              @click="detailsOpen = !detailsOpen"
            >
              <Building2 :size="19" aria-hidden="true" />
            </button>
          </header>
          <div
            ref="messageList"
            class="campaign-messages__timeline"
            :aria-label="t('campaignChat.title')"
          >
            <div
              v-for="message in conversationMessages"
              :key="message.id"
              class="campaign-messages__message-row"
              :class="{ 'campaign-messages__message-row--mine': isMessageMine(message) }"
            >
              <span
                v-if="!isMessageMine(message)"
                class="campaign-messages__avatar campaign-messages__avatar--message"
              >
                <img v-if="counterpartAvatar" :src="counterpartAvatar" alt="" loading="lazy">
                <span v-else>{{ initials(counterpartName) }}</span>
              </span>
              <article
                class="campaign-messages__message"
                :class="{ 'campaign-messages__message--mine': isMessageMine(message) }"
              >
                <p>{{ message.body }}</p>
                <time :datetime="message.createdAt">{{ messageTime(message.createdAt) }}</time>
              </article>
              <span
                v-if="isMessageMine(message)"
                class="campaign-messages__avatar campaign-messages__avatar--message"
              >
                <img v-if="currentAvatar" :src="currentAvatar" alt="" loading="lazy">
                <span v-else>{{ initials(currentDisplayName) || 'W' }}</span>
              </span>
            </div>
          </div>
          <form class="campaign-messages__composer" @submit.prevent="sendMessage">
            <label class="campaign-messages__composer-field">
              <span class="sr-only">{{ t('campaignChat.replyPlaceholder') }}</span>
              <input
                v-model="draft"
                type="text"
                maxlength="2000"
                required
                autocomplete="off"
                :placeholder="t('campaignChat.replyPlaceholder')"
                aria-describedby="campaign-message-enter-hint"
              >
              <span id="campaign-message-enter-hint" class="sr-only">
                {{ t('campaignChat.messageHint') }}
              </span>
            </label>
            <button
              class="campaign-messages__send"
              type="submit"
              :aria-label="sending ? t('campaignChat.sending') : t('campaignChat.send')"
              :title="sending ? t('campaignChat.sending') : t('campaignChat.send')"
              :disabled="!canSend"
            >
              <Send :size="16" aria-hidden="true" />
            </button>
          </form>
        </template>

        <template v-else-if="pendingStart && pendingDetails && currentUser?.accountType === 'company'">
          <header class="campaign-messages__thread-heading">
            <button
              class="campaign-messages__back"
              type="button"
              :aria-label="t('campaignChat.backToInbox')"
              @click="backToInbox"
            >
              <ChevronLeft :size="22" aria-hidden="true" />
            </button>
            <span class="campaign-messages__avatar campaign-messages__avatar--large">
              <img
                v-if="pendingDetails.creator.avatarUrl"
                :src="pendingDetails.creator.avatarUrl"
                alt=""
                loading="lazy"
              >
              <span v-else>{{ initials(pendingDetails.creator.displayName) }}</span>
            </span>
            <div class="campaign-messages__thread-person">
              <h2>{{ t('campaignChat.newConversationTitle') }}</h2>
              <p>{{ pendingDetails.creator.displayName }} · {{ pendingDetails.campaign.title }}</p>
            </div>
            <button
              class="campaign-messages__details-toggle"
              type="button"
              :aria-label="t('campaignChat.showDetails')"
              :aria-expanded="detailsOpen"
              aria-controls="campaign-details-panel"
              @click="detailsOpen = !detailsOpen"
            >
              <Building2 :size="19" aria-hidden="true" />
            </button>
          </header>
          <p class="campaign-messages__empty">{{ t('campaignChat.newConversationDescription') }}</p>
          <form class="campaign-messages__composer" @submit.prevent="sendMessage">
            <label class="campaign-messages__composer-field">
              <span class="sr-only">{{ t('campaignChat.firstMessage') }}</span>
              <input
                v-model="draft"
                type="text"
                maxlength="2000"
                required
                autocomplete="off"
                :placeholder="t('campaignChat.firstMessage')"
                aria-describedby="campaign-message-enter-hint"
              >
              <span id="campaign-message-enter-hint" class="sr-only">
                {{ t('campaignChat.messageHint') }}
              </span>
            </label>
            <button
              class="campaign-messages__send"
              type="submit"
              :aria-label="sending ? t('campaignChat.sending') : t('campaignChat.send')"
              :title="sending ? t('campaignChat.sending') : t('campaignChat.send')"
              :disabled="!canSend"
            >
              <Send :size="16" aria-hidden="true" />
            </button>
          </form>
        </template>

        <p
          v-else-if="pendingStart && currentUser && currentUser.accountType !== 'company'"
          class="campaign-messages__empty"
        >{{ t('campaignChat.companyOnlyStart') }}</p>
        <div v-else class="campaign-messages__empty">
          <MessageCircle :size="30" stroke-width="1.4" aria-hidden="true" />
          <p>{{ t('campaignChat.selectConversation') }}</p>
        </div>
      </section>

      <aside
        v-if="selectedCampaign"
        id="campaign-details-panel"
        class="campaign-messages__details"
        :class="{ 'is-open': detailsOpen }"
      >
        <header class="campaign-messages__details-mobile-heading">
          <h2>{{ t('campaignChat.showDetails') }}</h2>
          <button
            type="button"
            :aria-label="t('campaignChat.closeDetails')"
            @click="detailsOpen = false"
          >
            <ChevronLeft :size="22" aria-hidden="true" />
          </button>
        </header>
        <div class="campaign-messages__details-scroll">
          <article v-if="selectedCompany" class="campaign-messages__company-profile">
            <div class="campaign-messages__cover">
              <img
                v-if="selectedCampaign.coverImageUrl"
                :src="selectedCampaign.coverImageUrl"
                :alt="selectedCampaign.title"
                loading="lazy"
              >
            </div>
            <div class="campaign-messages__company-heading">
              <span class="campaign-messages__avatar campaign-messages__avatar--company">
                <img
                  v-if="selectedCompany.logoUrl"
                  :src="selectedCompany.logoUrl"
                  alt=""
                  loading="lazy"
                >
                <span v-else>{{ initials(selectedCompany.name) }}</span>
              </span>
              <div>
                <h2>{{ selectedCompany.name }}</h2>
                <p>{{ selectedCompany.industry }}</p>
              </div>
              <span
                v-if="selectedCompany.verified"
                class="campaign-messages__verified"
                :aria-label="t('campaignCard.verified')"
                :title="t('campaignCard.verified')"
              >
                <Check :size="15" stroke-width="2.5" aria-hidden="true" />
              </span>
            </div>
          </article>

          <article class="campaign-messages__campaign-card">
            <div class="campaign-messages__campaign-card-body">
              <div class="campaign-messages__campaign-card-heading">
                <div>
                  <p class="eyebrow">{{ t('campaignChat.campaign') }}</p>
                  <h2>{{ selectedCampaign.title }}</h2>
                </div>
                <span
                  class="campaign-messages__status-pill"
                  :class="{ 'is-open': selectedCampaign.status === 'open' }"
                >
                  <span aria-hidden="true"></span>
                  {{ t(selectedCampaign.status === 'open' ? 'campaignChat.open' : 'campaignChat.closed') }}
                </span>
              </div>
              <p class="campaign-messages__summary">{{ selectedCampaign.summary }}</p>
              <dl class="campaign-messages__facts">
                <div>
                  <dt><Wallet :size="16" aria-hidden="true" />{{ t('campaignChat.budget') }}</dt>
                  <dd>
                    {{ formatMoney(selectedCampaign.budgetMin, selectedCampaign.currency) }}
                    –
                    {{ formatMoney(selectedCampaign.budgetMax, selectedCampaign.currency) }}
                  </dd>
                </div>
                <div>
                  <dt><FileText :size="16" aria-hidden="true" />{{ t('campaignChat.deliverables') }}</dt>
                  <dd>{{ selectedCampaign.deliverables.join(' + ') }}</dd>
                </div>
                <div>
                  <dt><CalendarDays :size="16" aria-hidden="true" />{{ t('campaignChat.deadline') }}</dt>
                  <dd>{{ formatDate(selectedCampaign.closesAt) }}</dd>
                </div>
                <div>
                  <dt><MapPin :size="16" aria-hidden="true" />{{ t('campaignChat.location') }}</dt>
                  <dd>{{ selectedCampaign.location }}</dd>
                </div>
              </dl>
              <p v-if="selectedCampaign.channels.length" class="campaign-messages__channels">
                <Megaphone :size="15" aria-hidden="true" />
                {{ selectedCampaign.channels.join(' · ') }}
              </p>
              <details v-if="selectedCampaign.description" class="campaign-messages__brief">
                <summary>{{ t('campaignChat.fullBrief') }}</summary>
                <p>{{ selectedCampaign.description }}</p>
              </details>
              <LocalizedLink
                class="campaign-messages__campaign-link"
                :to="{ name: 'campaign-detail', params: { slug: selectedCampaign.slug } }"
              >
                {{ t('campaignChat.viewCampaign') }}
                <ArrowRight :size="16" aria-hidden="true" />
              </LocalizedLink>
            </div>
          </article>
        </div>
      </aside>
      <aside
        v-else-if="selectedInquiry"
        id="campaign-details-panel"
        class="campaign-messages__details"
        :class="{ 'is-open': detailsOpen }"
      >
        <header class="campaign-messages__details-mobile-heading">
          <h2>{{ t('campaignChat.directInquiry') }}</h2>
          <button
            type="button"
            :aria-label="t('campaignChat.closeDetails')"
            @click="detailsOpen = false"
          >
            <ChevronLeft :size="22" aria-hidden="true" />
          </button>
        </header>
        <div class="campaign-messages__details-scroll">
          <article class="campaign-messages__campaign-card">
            <div class="campaign-messages__campaign-card-body">
              <p class="eyebrow">{{ t('campaignChat.directInquiry') }}</p>
              <h2>{{ selectedInquiry.packageTitle || t('campaignChat.directInquiry') }}</h2>
              <p class="campaign-messages__summary">{{ selectedInquiry.message }}</p>
              <p v-if="selectedInquiry.proposedAmount || selectedInquiry.listedPrice" class="campaign-messages__summary">
                {{ formatMoney(
                  selectedInquiry.proposedAmount ?? selectedInquiry.listedPrice,
                  selectedInquiry.proposedAmount ? selectedInquiry.currency : selectedInquiry.listedPriceCurrency,
                ) }}
              </p>
            </div>
          </article>
        </div>
      </aside>
      <aside v-else class="campaign-messages__details campaign-messages__details--empty">
        <MessageCircle :size="26" stroke-width="1.4" aria-hidden="true" />
        <p>{{ t('campaignChat.detailsPrompt') }}</p>
      </aside>
    </div>
  </section>
</template>
