<script setup>
import CampaignHiringProgress from '../components/campaigns/CampaignHiringProgress.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowRight,
  Building2,
  CalendarDays,
  Check,
  CheckCheck,
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
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import InboxSkeleton from '../components/messages/InboxSkeleton.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser, loadCurrentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { localizedRouteName } from '../routePaths'
import { areChatMessagesGrouped, createChatTimeFormatter, groupChatMessages } from '../lib/chatTime'
import { campaignPlace } from '../lib/marketplace'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const conversations = ref([])
const inquiries = ref([])
const selectedDetails = ref(null)
const loadingInboxPage = ref(false)
const inboxPageError = ref('')
const hasMoreInbox = ref(true)
let inboxCursor = null
let inboxFilterTimer = 0
const threadRevisions = new Map()
const conversationMessages = ref([])
// Keep this visit's boundary after the server acknowledges reads.
const firstUnreadMessageId = ref(null)
let unreadBoundaryAcknowledged = false
const unreadDividerMessageId = computed(() => firstUnreadMessageId.value === null ? null
  : conversationMessages.value.find((message) => !isMessageMine(message) && message.id >= firstUnreadMessageId.value)?.id || null)
const hasEarlierUnreadMessages = computed(() => firstUnreadMessageId.value !== null
  && (conversationMessages.value[0]?.id || 0) > firstUnreadMessageId.value)
const hasOlderMessages = ref(false)
const loadingOlderMessages = ref(false)
const historyError = ref('')
const atLatestMessage = ref(true)
const unseenNewMessages = ref(0)
const loadingMessages = ref(false)
const PAGE_SIZE = 50
let readInProgress = false
let historyRequestVersion = 0
let counterpartReadCursor = 0
let counterpartReadAt = null
const chatClock = ref(Date.now())
const chatTime = computed(() => createChatTimeFormatter(locale.value, t))
const messageDays = computed(() => groupChatMessages(conversationMessages.value))
let chatClockTimer = 0
const pendingDetails = ref(null)
const selectedConversationId = ref(null)
const selectedInquiryId = ref(null)
const detailsOpen = ref(false)
const isMobileView = ref(false)
const visualViewportHeight = ref(0)
const visualViewportTop = ref(0)
const keyboardOpen = ref(false)
const composerInput = ref(null)
const composerFocused = ref(false)
let viewportLayoutHeight = 0
let viewportLayoutWidth = 0
let viewportUpdateFrame = 0
let viewportSettleTimers = []
const conversationQuery = ref('')
const conversationFilter = ref('all')
const draft = ref('')
const loading = ref(true)
const inboxLoaded = ref(false)
const sending = ref(false)
const error = ref('')
const authRequired = ref(false)
const inboxList = ref(null)
const messageList = ref(null)
let messageScrollFrame = 0
let inboxRefreshInProgress = false
let inboxRefreshPending = false
let messagesRequestVersion = 0
let inboxRequestVersion = 0
let inboxOpenVersion = 0
let unmounted = false

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

const selectedConversation = computed(() => (selectedDetails.value?.threadType === 'campaign'
  && selectedDetails.value.id === selectedConversationId.value ? selectedDetails.value : null) || conversations.value.find(
  (conversation) => conversation.id === selectedConversationId.value,
) || null)
const selectedInquiry = computed(() => (selectedDetails.value?.threadType === 'inquiry'
  && selectedDetails.value.id === selectedInquiryId.value ? selectedDetails.value : null) || inquiries.value.find(
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
  selectedConversationId.value !== null || selectedInquiryId.value !== null || (pendingStart.value && pendingDetails.value),
))
const viewportStyle = computed(() => ({
  '--chat-viewport-height': `${visualViewportHeight.value}px`,
  '--chat-viewport-top': `${visualViewportTop.value}px`,
  '--chat-bottom-inset': keyboardOpen.value ? '0px' : 'env(safe-area-inset-bottom, 0px)',
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
      unreadCount: inquiry.unreadCount || 0,
    })),
].sort((first, second) => new Date(second.lastMessageAt) - new Date(first.lastMessageAt)
  || first.threadType.localeCompare(second.threadType) || second.id - first.id))

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
// Inbox rows only contain a title and slug. Render the brief after the
// authorized thread endpoint supplies its complete campaign resource.
const campaignDetailsReady = computed(() => Boolean(
  (selectedDetails.value?.threadType === 'campaign' && selectedDetails.value.id === selectedConversationId.value)
  || pendingDetails.value?.campaign,
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
const campaignChatClosed = computed(() => selectedThread.value?.readOnly || ['closed', 'finished'].includes(selectedCampaign.value?.status))
const campaignChatNotice = computed(() => selectedThread.value?.readOnly ? 'campaignChat.deletedAccountNotice' : selectedCampaign.value?.status === 'finished' ? 'campaignChat.finishedNotice' : 'campaignChat.closedNotice')
const canSend = computed(() => Boolean(
  !sending.value
  && !campaignChatClosed.value
  && !loadingMessages.value
  && draft.value.trim().length > 0
  && draft.value.trim().length <= 2000
  && (selectedThread.value || (
    pendingStart.value
    && currentUser.value?.accountType === 'company'
    && pendingDetails.value
  ))
))

watch([conversationQuery, conversationFilter, locale], () => {
  window.clearTimeout(inboxFilterTimer)
  inboxRequestVersion += 1
  conversations.value = []
  inquiries.value = []
  inboxCursor = null
  hasMoreInbox.value = true
  inboxPageError.value = ''
  loadingInboxPage.value = true
  const load = () => {
    loadingInboxPage.value = false
    void loadInboxPage()
  }
  if (conversationQuery.value.trim()) inboxFilterTimer = window.setTimeout(load, 250)
  else load()
}, { flush: 'sync' })

watch([isMobileView, mobileThreadOpen], ([mobile, threadOpen]) => {
  document.documentElement.classList.toggle('wave-chat-open', mobile && threadOpen)
  if (!mobile) detailsOpen.value = false
})

watch(
  () => [route.query.conversation, route.query.inquiry],
  () => {
    if (unmounted) return
    const conversationId = Number(route.query.conversation)
    const inquiryId = Number(route.query.inquiry)
    if (conversationId === selectedConversationId.value || inquiryId === selectedInquiryId.value) return
    // Vue Router reuses this view when only the notification's chat query changes.
    inboxLoaded.value = false
    void loadInbox()
  },
)

watch(
  [
    selectedConversationId,
    selectedInquiryId,
    mobileThreadOpen,
    visualViewportHeight,
  ],
  () => { if (atLatestMessage.value) void scrollToLatestMessage() },
  { flush: 'post' },
)

onMounted(() => {
  updateViewport()
  updateChatClock()
  document.addEventListener('visibilitychange', updateChatClock)
  window.addEventListener('resize', scheduleViewportUpdate)
  window.visualViewport?.addEventListener('resize', scheduleViewportUpdate)
  window.visualViewport?.addEventListener('scroll', scheduleViewportUpdate)
  document.addEventListener('visibilitychange', scheduleViewportUpdate)
  void loadInbox()
  window.addEventListener('wave:realtime', handleRealtimeUpdate)
  window.addEventListener('wave:inbox-refresh', refreshInboxWhenVisible)
  document.addEventListener('visibilitychange', acknowledgeVisibleMessages)
})

onBeforeUnmount(() => {
  unmounted = true
  messagesRequestVersion += 1
  historyRequestVersion += 1
  inboxRequestVersion += 1
  window.clearTimeout(inboxFilterTimer)
  document.documentElement.classList.remove('wave-chat-open')
  window.clearTimeout(chatClockTimer)
  document.removeEventListener('visibilitychange', updateChatClock)
  window.removeEventListener('resize', scheduleViewportUpdate)
  window.visualViewport?.removeEventListener('resize', scheduleViewportUpdate)
  window.visualViewport?.removeEventListener('scroll', scheduleViewportUpdate)
  document.removeEventListener('visibilitychange', scheduleViewportUpdate)
  clearViewportUpdates()
  window.removeEventListener('wave:realtime', handleRealtimeUpdate)
  window.removeEventListener('wave:inbox-refresh', refreshInboxWhenVisible)
  document.removeEventListener('visibilitychange', acknowledgeVisibleMessages)
  if (messageScrollFrame) {
    window.cancelAnimationFrame(messageScrollFrame)
  }
})

function updateChatClock() {
  window.clearTimeout(chatClockTimer)
  if (document.visibilityState !== 'visible' || unmounted) return
  chatClock.value = Date.now()
  // Refresh only the displayed clock. Messages continue to arrive through Mercure.
  chatClockTimer = window.setTimeout(updateChatClock, 60_000 - Date.now() % 60_000)
}

function updateViewport() {
  isMobileView.value = window.matchMedia('(max-width: 760px)').matches
  const viewport = window.visualViewport
  const height = Math.round(viewport?.height || window.innerHeight)
  const width = Math.round(window.innerWidth || viewport?.width || 0)
  const layoutHeight = Math.max(window.innerHeight, document.documentElement.clientHeight || 0)
  // Preserve the pre-keyboard height when an installed PWA resizes every viewport.
  if (width !== viewportLayoutWidth || (!composerFocused.value && !keyboardOpen.value)) {
    viewportLayoutWidth = width
    viewportLayoutHeight = layoutHeight
  } else {
    viewportLayoutHeight = Math.max(viewportLayoutHeight, layoutHeight)
  }
  const keyboardLayoutHeight = composerFocused.value || keyboardOpen.value
    ? Math.max(layoutHeight, viewportLayoutHeight) : layoutHeight
  keyboardOpen.value = isMobileView.value
    && Boolean(viewport)
    && Math.abs((viewport.scale || 1) - 1) < 0.01
    && keyboardLayoutHeight - height > 120
  visualViewportHeight.value = height
  // WebKit can retain a stale pan offset after the keyboard has closed.
  visualViewportTop.value = keyboardOpen.value ? Math.max(0, Math.round(viewport?.offsetTop || 0)) : 0
}

function clearViewportUpdates() {
  if (viewportUpdateFrame) window.cancelAnimationFrame(viewportUpdateFrame)
  viewportUpdateFrame = 0
  viewportSettleTimers.forEach((timer) => window.clearTimeout(timer))
  viewportSettleTimers = []
}

function scheduleViewportUpdate() {
  clearViewportUpdates()
  if (unmounted || document.visibilityState !== 'visible') return
  updateViewport()
  viewportUpdateFrame = window.requestAnimationFrame(() => {
    viewportUpdateFrame = 0
    if (!unmounted) updateViewport()
  })
  // Keyboard/emoji-panel geometry may settle after the resize event. This is a
  // bounded layout check, with no interval and no message or notification fetch.
  if (isMobileView.value) {
    viewportSettleTimers = [100, 350, 750].map((delay) => window.setTimeout(() => {
      if (!unmounted && document.visibilityState === 'visible') updateViewport()
    }, delay))
  }
}

function handleComposerFocus() {
  // Keep the idle viewport baseline even if WebKit has already started resizing.
  composerFocused.value = true
  scheduleViewportUpdate()
}

function handleComposerBlur() {
  composerFocused.value = false
  scheduleViewportUpdate()
}

function preserveComposerFocus(event) {
  if (isMobileView.value && document.activeElement === composerInput.value
    && event.button === 0 && event.isPrimary !== false) {
    // Cancel focus transfer, keeping the normal click/form submission intact.
    event.preventDefault()
  }
}

function focusComposerForSend(event) {
  const input = composerInput.value
  if (isMobileView.value && keyboardOpen.value && input && event?.submitter) {
    // Button activation does not focus the button in every touch browser.
    // Run inside the original submit gesture; iOS rejects focus after await.
    input.focus({ preventScroll: true })
  }
}

function handleRealtimeUpdate(event) {
  const update = event.detail
  const isInquiry = update?.inquiryId !== undefined
  const id = Number(isInquiry ? update.inquiryId : update?.conversationId)
  const isSelected = (isInquiry ? selectedInquiryId.value : selectedConversationId.value) === id
  if (update?.type === 'chat_read') {
    if (isSelected && update.readerId !== currentUser.value?.id && update.throughId > counterpartReadCursor) {
      counterpartReadCursor = update.throughId
      counterpartReadAt = update.readAt
      mergeMessages([])
    }
    return
  }
  if (document.visibilityState !== 'visible') return
  const message = update?.message
  if ((update?.notificationType !== 'chat_message' && update?.type !== 'chat_message') || !message || !Number.isInteger(id)) {
    void refreshInbox()
    return
  }
  const collection = isInquiry ? inquiries.value : conversations.value
  const thread = collection.find((item) => item.id === id) || (isSelected ? selectedThread.value : null)
  if (!thread) {
    void loadLiveThread(isInquiry ? 'inquiry' : 'campaign', id)
    return
  }
  const isIncoming = message.senderId !== currentUser.value?.id
  const duplicate = isSelected && conversationMessages.value.some((existing) => existing.id === message.id)
  if (duplicate) return
  markInboxThreadChanged(isInquiry ? 'inquiry' : 'campaign', id)
  Object.assign(thread, {
    lastMessage: message.body,
    lastMessageAt: message.createdAt,
    lastMessageSenderId: message.senderId,
    unreadCount: (thread.unreadCount || 0) + (isIncoming ? 1 : 0),
  })
  if (isSelected) {
    if (selectedDetails.value) Object.assign(selectedDetails.value, thread)
    if (isIncoming && !atLatestMessage.value) rememberUnreadBoundary([message])
    mergeMessages([message])
    if (atLatestMessage.value) {
      void scrollToLatestMessage()
    } else {
      unseenNewMessages.value += 1
    }
  } else if (isIncoming && !isInquiry) {
    window.dispatchEvent(new Event('wave:conversations-updated'))
  }
}

function refreshInboxWhenVisible() {
  if (document.visibilityState === 'visible' && currentUser.value && !loading.value) {
    void refreshInbox()
  }
}

async function loadInboxPage(refresh = false) {
  if (unmounted || (!refresh && (loadingInboxPage.value || !hasMoreInbox.value))) return false
  const requestVersion = ++inboxRequestVersion
  const userId = currentUser.value?.id
  const revisions = new Map(threadRevisions)
  const previousCursor = inboxCursor
  const previousHasMore = hasMoreInbox.value
  const hadItems = threads.value.length > 0
  const params = new URLSearchParams({ limit: '30', filter: conversationFilter.value })
  if (conversationQuery.value.trim()) params.set('q', conversationQuery.value.trim())
  if (!refresh && inboxCursor) params.set('cursor', inboxCursor)
  loadingInboxPage.value = true
  inboxPageError.value = ''
  try {
    const response = await apiGet(`/me/inbox?${params}`, { locale: locale.value })
    if (unmounted || requestVersion !== inboxRequestVersion || currentUser.value?.id !== userId) return false
    if (!Array.isArray(response.data)) throw new Error(t('api.invalidResponse'))
    for (const thread of response.data) {
      const key = `${thread.threadType}-${thread.id}`
      const collection = thread.threadType === 'inquiry' ? inquiries.value : conversations.value
      // A slow batch must not undo a live preview, sent message or read receipt.
      if ((threadRevisions.get(key) || 0) !== (revisions.get(key) || 0)
        && collection.some((item) => item.id === thread.id)) continue
      if (thread.threadType === 'inquiry') upsertInquiry(thread)
      else upsertConversation(thread)
    }
    // Event-driven refreshes update the head without discarding older loaded rows
    // or moving their pagination cursor. Filters start a fresh generation.
    if (refresh && hadItems) {
      inboxCursor = previousCursor
      hasMoreInbox.value = previousHasMore
    } else {
      inboxCursor = response.meta?.nextCursor || null
      hasMoreInbox.value = Boolean(response.meta?.hasMore && inboxCursor)
    }
    inboxLoaded.value = true
    return true
  } catch (cause) {
    if (!unmounted && requestVersion === inboxRequestVersion && currentUser.value?.id === userId) {
      inboxPageError.value = cause.message
      if (cause.status === 401) handleLoadError(cause)
    }
    return false
  } finally {
    if (requestVersion === inboxRequestVersion) loadingInboxPage.value = false
  }
}

async function loadInbox() {
  const openVersion = ++inboxOpenVersion
  messagesRequestVersion += 1
  if (route.query.conversation === undefined && route.query.inquiry === undefined && !pendingStart.value) {
    backToInbox(false)
  }
  loading.value = true
  error.value = ''
  authRequired.value = false
  try {
    if (!currentUser.value) await loadCurrentUser()
    if (!await loadInboxPage(true) || unmounted || openVersion !== inboxOpenVersion) return
    const conversationId = Number(route.query.conversation)
    const inquiryId = Number(route.query.inquiry)
    if (route.query.conversation !== undefined || route.query.inquiry !== undefined) {
      const type = route.query.inquiry !== undefined ? 'inquiry' : 'campaign'
      const id = type === 'inquiry' ? inquiryId : conversationId
      if (!Number.isSafeInteger(id) || id < 1) {
        backToInbox(false)
        return
      }
      await openInboxThread(type, id, false)
      return
    }
    if (pendingStart.value) {
      const existing = conversations.value.find((item) => (
        item.campaign.slug === pendingStart.value.campaign
        && item.creator.id === pendingStart.value.creatorId
      ))
      if (existing) {
        await selectConversation({ ...existing, threadType: 'campaign', threadKey: `campaign-${existing.id}` })
      } else if (currentUser.value?.accountType === 'company') {
        await loadPendingDetails()
      }
      return
    }
  } catch (cause) {
    if (!unmounted && openVersion === inboxOpenVersion) handleLoadError(cause)
  } finally {
    if (!unmounted && openVersion === inboxOpenVersion) loading.value = false
  }
}

async function openInboxThread(type, id, syncRoute = true) {
  return selectConversation({ id, threadType: type, threadKey: `${type}-${id}` }, syncRoute)
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
  const selectionVersion = ++messagesRequestVersion
  const userId = currentUser.value?.id
  if (selectedThreadKey.value !== thread.threadKey) resetHistory()
  selectedConversationId.value = thread.threadType === 'campaign' ? thread.id : null
  selectedInquiryId.value = thread.threadType === 'inquiry' ? thread.id : null
  selectedDetails.value = null
  pendingDetails.value = null
  detailsOpen.value = false
  loadingMessages.value = true
  const isCurrent = () => !unmounted && messagesRequestVersion === selectionVersion && currentUser.value?.id === userId
  try {
    const response = await apiGet(`/me/inbox/${thread.threadType}/${thread.id}`, { locale: locale.value })
    if (!isCurrent()) return
    if (response.data?.id !== thread.id || response.data?.threadType !== thread.threadType) throw new Error(t('api.invalidResponse'))
    selectedDetails.value = response.data
    // Metadata for a deep link need not belong to the currently filtered page.
    if (syncRoute) {
      await router.replace({
        name: localizedRouteName('messages', locale.value),
        query: thread.threadType === 'inquiry' ? { inquiry: thread.id } : { conversation: thread.id },
      })
    }
    if (!isCurrent()) return
    await loadMessages(response.data, true, true)
  } catch (cause) {
    if (isCurrent()) {
      backToInbox(false)
      handleLoadError(cause)
    }
  } finally {
    if (isCurrent()) loadingMessages.value = false
  }
}

function backToInbox(syncRoute = true) {
  messagesRequestVersion += 1
  detailsOpen.value = false
  selectedConversationId.value = null
  selectedInquiryId.value = null
  selectedDetails.value = null
  pendingDetails.value = null
  resetHistory()
  if (!syncRoute) return
  void router.replace({
    name: localizedRouteName('messages', locale.value),
    query: {},
  })
}

function resetHistory() {
  historyRequestVersion += 1
  conversationMessages.value = []
  firstUnreadMessageId.value = null
  unreadBoundaryAcknowledged = false
  hasOlderMessages.value = false
  loadingOlderMessages.value = false
  loadingMessages.value = false
  historyError.value = ''
  atLatestMessage.value = true
  unseenNewMessages.value = 0
  counterpartReadCursor = 0
  counterpartReadAt = null
}

function threadPath(thread) {
  return `/me/${thread.threadType === 'inquiry' ? 'inquiries' : 'conversations'}/${thread.id}`
}

function activeThread() {
  return selectedThread.value && {
    ...selectedThread.value,
    threadType: selectedInquiryId.value === null ? 'campaign' : 'inquiry',
  }
}

function rememberUnreadBoundary(messages, serverFirstUnreadId = null) {
  const firstIncoming = messages.find((message) => !isMessageMine(message) && !message.readAt)
  const boundary = serverFirstUnreadId || firstIncoming?.id
  if (!boundary) return
  firstUnreadMessageId.value = firstUnreadMessageId.value === null || unreadBoundaryAcknowledged
    ? boundary : Math.min(firstUnreadMessageId.value, boundary)
  unreadBoundaryAcknowledged = false
}

function chatMessagesAreGrouped(previous, message) {
  return message?.id !== unreadDividerMessageId.value && areChatMessagesGrouped(previous, message)
}

function mergeMessages(messages) {
  const merged = new Map(conversationMessages.value.map((message) => [message.id, message]))
  for (const message of messages) {
    const existing = merged.get(message.id)
    merged.set(message.id, { ...existing, ...message, readAt: message.readAt || existing?.readAt || null })
  }
  conversationMessages.value = [...merged.values()].sort((left, right) => left.id - right.id)
    .map((message) => (isMessageMine(message) && message.id <= counterpartReadCursor
      ? { ...message, readAt: message.readAt || counterpartReadAt }
      : message))
}

async function loadMessages(thread, forceScroll = false, startWithLatestPage = false) {
  const requestVersion = ++messagesRequestVersion
  const userId = currentUser.value?.id
  const isCurrentRequest = () => (
    !unmounted && requestVersion === messagesRequestVersion && currentUser.value?.id === userId
    && (thread.threadType === 'inquiry' ? selectedInquiryId.value : selectedConversationId.value) === thread.id
  )
  const initial = startWithLatestPage || conversationMessages.value.length === 0
  loadingMessages.value = true
  try {
    let cursor = initial ? null : conversationMessages.value.at(-1)?.id
    let hasMore = false
    do {
      const response = await apiGet(`${threadPath(thread)}/messages?limit=${PAGE_SIZE}${cursor ? `&after=${cursor}` : ''}`)
      if (!isCurrentRequest()) return
      if (response.readReceipt?.throughId > counterpartReadCursor) {
        counterpartReadCursor = response.readReceipt.throughId
        counterpartReadAt = response.readReceipt.readAt
      }
      rememberUnreadBoundary(response.data, response.meta?.firstUnreadId)
      mergeMessages(response.data)
      if (initial) hasOlderMessages.value = Boolean(response.meta?.hasMoreOlder)
      if (response.conversation) upsertConversation(response.conversation)
      hasMore = Boolean(response.meta?.hasMoreNewer)
      const nextCursor = response.data.at(-1)?.id
      if (!nextCursor || nextCursor === cursor) break
      cursor = nextCursor
    } while (hasMore)
    error.value = ''
    if (initial && unreadDividerMessageId.value !== null) await scrollToUnreadMessage()
    else if (forceScroll || atLatestMessage.value) await scrollToLatestMessage()
  } catch (cause) {
    if (isCurrentRequest()) handleLoadError(cause)
  } finally {
    if (isCurrentRequest()) loadingMessages.value = false
  }
}

async function loadOlderMessages() {
  const thread = activeThread()
  if (!thread || !hasOlderMessages.value || loadingOlderMessages.value || loadingMessages.value) return
  const requestVersion = ++historyRequestVersion
  const selectionVersion = messagesRequestVersion
  const userId = currentUser.value?.id
  const isCurrent = () => !unmounted && requestVersion === historyRequestVersion
    && selectionVersion === messagesRequestVersion && currentUser.value?.id === userId
  const timeline = messageList.value
  const anchor = timeline?.querySelector('[data-message-id]')
  const anchorTop = anchor?.getBoundingClientRect().top
  const anchorId = anchor?.dataset.messageId
  loadingOlderMessages.value = true
  atLatestMessage.value = false
  historyError.value = ''
  try {
    const response = await apiGet(`${threadPath(thread)}/messages?limit=${PAGE_SIZE}&before=${conversationMessages.value[0].id}`)
    if (!isCurrent()) return
    rememberUnreadBoundary(response.data, response.meta?.firstUnreadId)
    mergeMessages(response.data)
    hasOlderMessages.value = Boolean(response.meta?.hasMoreOlder)
  } catch (cause) {
    if (isCurrent()) historyError.value = cause.message
  } finally {
    if (requestVersion === historyRequestVersion) loadingOlderMessages.value = false
    await nextTick()
    if (isCurrent()) {
      const retained = anchorId && timeline?.querySelector(`[data-message-id="${anchorId}"]`)
      if (retained) timeline.scrollTop += retained.getBoundingClientRect().top - anchorTop
    }
  }
}

function handleTimelineScroll() {
  const timeline = messageList.value
  if (!timeline || loadingOlderMessages.value) return
  atLatestMessage.value = timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight < 60
  if (atLatestMessage.value) {
    unseenNewMessages.value = 0
    void acknowledgeVisibleMessages()
  }
  if (timeline.scrollTop < 120) void loadOlderMessages()
}

async function acknowledgeVisibleMessages() {
  const thread = activeThread()
  const throughId = conversationMessages.value.at(-1)?.id
  if (!thread || !messageList.value || document.visibilityState !== 'visible' || !atLatestMessage.value
    || loadingMessages.value || readInProgress || !throughId) return
  if (!conversationMessages.value.some((message) => !isMessageMine(message) && !message.readAt)) {
    return
  }
  const key = selectedThreadKey.value
  const userId = currentUser.value?.id
  readInProgress = true
  let acknowledged = false
  try {
    const response = await apiRequest(`${threadPath(thread)}/read`, { method: 'POST', body: { throughId } })
    if (unmounted || selectedThreadKey.value !== key || currentUser.value?.id !== userId) return
    acknowledged = true
    if (firstUnreadMessageId.value !== null && throughId >= firstUnreadMessageId.value) unreadBoundaryAcknowledged = true
    conversationMessages.value = conversationMessages.value.map((message) => (
      !isMessageMine(message) && message.id <= throughId
        ? { ...message, readAt: message.readAt || response.data.readAt } : message
    ))
    if (!atLatestMessage.value) rememberUnreadBoundary(conversationMessages.value.filter((message) => message.id > throughId))
    const remainingUnread = conversationMessages.value.filter((message) => (
      !isMessageMine(message) && message.id > throughId && !message.readAt
    )).length
    markInboxThreadChanged(thread.threadType, thread.id)
    if (response.conversation) {
      upsertConversation({ ...response.conversation, unreadCount: Math.max(response.conversation.unreadCount || 0, remainingUnread) })
    } else if (selectedInquiry.value) {
      upsertInquiry({ ...selectedInquiry.value, unreadCount: remainingUnread })
    }
    window.dispatchEvent(new Event('wave:conversations-updated'))
  } catch (cause) {
    // Retry on the next scroll/visibility/message event, without a polling timer.
    if (selectedThreadKey.value === key) error.value = cause.message
  } finally {
    readInProgress = false
    if (acknowledged || selectedThreadKey.value !== key) void acknowledgeVisibleMessages()
  }
}

async function scrollToUnreadMessage() {
  await nextTick()
  if (messageScrollFrame) window.cancelAnimationFrame(messageScrollFrame)
  messageScrollFrame = window.requestAnimationFrame(() => {
    messageScrollFrame = 0
    const timeline = messageList.value
    const divider = timeline?.querySelector('.campaign-messages__unread-divider')
    if (!timeline || !divider) return
    timeline.scrollTop += divider.getBoundingClientRect().top - timeline.getBoundingClientRect().top - 12
    atLatestMessage.value = timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight < 60
    if (atLatestMessage.value) void acknowledgeVisibleMessages()
  })
}

async function scrollToLatestMessage() {
  await nextTick()
  if (messageScrollFrame) window.cancelAnimationFrame(messageScrollFrame)
  messageScrollFrame = window.requestAnimationFrame(() => {
    messageScrollFrame = 0
    const timeline = messageList.value
    if (timeline) {
      timeline.scrollTop = timeline.scrollHeight
      atLatestMessage.value = true
      unseenNewMessages.value = 0
      void acknowledgeVisibleMessages()
    }
  })
}

async function refreshInbox() {
  if (unmounted || document.visibilityState !== 'visible') return
  if (inboxRefreshInProgress) {
    inboxRefreshPending = true
    return
  }
  inboxRefreshInProgress = true
  const userId = currentUser.value?.id
  try {
    if (!await loadInboxPage(true)) return
    if (unmounted || currentUser.value?.id !== userId) return
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
    if (inboxRefreshPending) {
      inboxRefreshPending = false
      void refreshInbox()
    }
  }
}

function markInboxThreadChanged(type, id) {
  const key = `${type}-${id}`
  threadRevisions.set(key, (threadRevisions.get(key) || 0) + 1)
}

function upsertThread(collection, thread, type) {
  const existingIndex = collection.value.findIndex((item) => item.id === thread.id)
  const existing = existingIndex === -1 ? null : collection.value[existingIndex]
  const merged = {
    ...existing,
    ...thread,
    threadType: type,
    campaign: { ...existing?.campaign, ...thread.campaign },
  }
  if (existingIndex === -1) {
    collection.value.push(merged)
  } else {
    collection.value.splice(existingIndex, 1, merged)
  }
  if (selectedDetails.value?.threadType === type && selectedDetails.value.id === thread.id) {
    selectedDetails.value = {
      ...selectedDetails.value,
      ...merged,
      campaign: { ...selectedDetails.value.campaign, ...merged.campaign },
    }
  }
}

function upsertConversation(conversation) {
  upsertThread(conversations, conversation, 'campaign')
}

function upsertInquiry(inquiry) {
  upsertThread(inquiries, inquiry, 'inquiry')
}

async function loadLiveThread(type, id) {
  const version = inboxRequestVersion
  const userId = currentUser.value?.id
  try {
    const response = await apiGet(`/me/inbox/${type}/${id}`, { locale: locale.value })
    if (unmounted || version !== inboxRequestVersion || currentUser.value?.id !== userId) return
    if (response.data?.id !== id || response.data?.threadType !== type) return
    if (type === 'inquiry') upsertInquiry(response.data)
    else upsertConversation(response.data)
  } catch (cause) {
    if (!unmounted && version === inboxRequestVersion && cause.status !== 404) inboxPageError.value = cause.message
  }
}

async function sendMessage(event) {
  if (!canSend.value) {
    return
  }
  focusComposerForSend(event)
  sending.value = true
  error.value = ''
  const body = draft.value.trim()
  const senderId = currentUser.value?.id
  const threadKey = selectedThreadKey.value
  const isCurrentSend = () => !unmounted && currentUser.value?.id === senderId && selectedThreadKey.value === threadKey
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
      if (!isCurrentSend()) return
      resetHistory()
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
      if (!isCurrentSend()) return
      mergeMessages([response.data])
      const inquiry = selectedInquiry.value
      if (inquiry) {
        markInboxThreadChanged('inquiry', inquiry.id)
        upsertInquiry({ ...inquiry, lastMessage: body, lastMessageAt: response.data.createdAt,
          lastMessageSenderId: currentUser.value?.id })
      }
      await scrollToLatestMessage()
    } else if (selectedConversationId.value !== null) {
      const response = await apiRequest(
        `/me/conversations/${selectedConversationId.value}/messages`,
        { method: 'POST', body: { body } },
      )
      if (!isCurrentSend()) return
      mergeMessages([response.data])
      const conversation = selectedConversation.value
      if (conversation) {
        markInboxThreadChanged('campaign', conversation.id)
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
    if (isCurrentSend()) error.value = cause.message
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

function messageTimeDescription(value) {
  return [chatTime.value.full(value), chatTime.value.ago(value, chatClock.value)]
    .filter(Boolean)
    .join(' · ')
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
    <InboxSkeleton v-else-if="loading && !inboxLoaded" :open-chat="Boolean(route.query.conversation || route.query.inquiry || route.query.creator || route.query.company)" />
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
          <input v-model="conversationQuery" maxlength="200" type="search" :placeholder="t('campaignChat.searchPlaceholder')">
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

        <div ref="inboxList" class="campaign-messages__conversation-list" :aria-busy="loadingInboxPage">
          <p v-if="!loadingInboxPage && !inboxPageError && !filteredConversations.length" class="campaign-messages__no-results">
            {{ t(conversationQuery || conversationFilter === 'unread' ? 'campaignChat.noSearchResults' : 'campaignChat.noConversations') }}
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
                <time
                  :datetime="conversation.lastMessageAt"
                  :title="chatTime.full(conversation.lastMessageAt)"
                  aria-live="off"
                >{{ chatTime.ago(conversation.lastMessageAt, chatClock) }}</time>
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
          <LoadingSkeleton v-if="loadingInboxPage" variant="rows" :count="3" />
          <DirectoryLoadMore
            v-if="inboxLoaded || inboxPageError"
            :loading="loadingInboxPage"
            :has-more="hasMoreInbox"
            :error="inboxPageError"
            :count="threads.length"
            :root="inboxList"
            @load="loadInboxPage()"
          />
        </div>
      </aside>

      <section class="campaign-messages__thread" aria-live="polite">
        <LoadingSkeleton v-if="loadingMessages && !selectedDetails" variant="messages" :label="t('campaignChat.loadingMessages')" />
        <template v-else-if="selectedThread">
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
            role="region"
            tabindex="0"
            :aria-label="t('campaignChat.title')"
            :aria-busy="loadingMessages || loadingOlderMessages"
            @scroll.passive="handleTimelineScroll"
          >
            <LoadingSkeleton v-if="loadingMessages && !conversationMessages.length" variant="messages" :label="t('campaignChat.loadingMessages')" />
            <LoadingSkeleton v-if="loadingOlderMessages" variant="messages" :count="2" :label="t('campaignChat.loadingOlder')" />
            <button v-if="hasOlderMessages" class="campaign-messages__history-button" type="button" :disabled="loadingOlderMessages || loadingMessages" @click="loadOlderMessages">
              {{ t(loadingOlderMessages ? 'campaignChat.loadingOlder' : hasEarlierUnreadMessages ? 'campaignChat.loadEarlierUnread' : 'campaignChat.loadOlder') }}
            </button>
            <p v-if="historyError" class="campaign-messages__history-status" role="alert">{{ historyError }}</p>
            <div v-for="day in messageDays" :key="day.key" class="campaign-messages__day">
              <div v-if="day.dateKey" class="campaign-messages__day-divider" aria-live="off">
                <span>{{ chatTime.dayLabel(day.createdAt, chatClock) }}</span>
              </div>
              <template v-for="(message, index) in day.messages" :key="message.id">
                <div
                  v-if="message.id === unreadDividerMessageId"
                  class="campaign-messages__unread-divider"
                  role="separator"
                  :aria-label="t('campaignChat.unreadDivider')"
                >
                  <span>{{ t('campaignChat.unreadDivider') }}</span>
                </div>
                <div
                  :data-message-id="message.id"
                  class="campaign-messages__message-row"
                  :class="{
                    'campaign-messages__message-row--mine': isMessageMine(message),
                    'campaign-messages__message-row--grouped': chatMessagesAreGrouped(day.messages[index - 1], message),
                  }"
                >
                  <span
                    v-if="!isMessageMine(message)"
                    class="campaign-messages__avatar campaign-messages__avatar--message"
                    :class="{ 'campaign-messages__avatar--grouped': chatMessagesAreGrouped(message, day.messages[index + 1]) }"
                  >
                    <img v-if="counterpartAvatar" :src="counterpartAvatar" alt="" loading="lazy">
                    <span v-else>{{ initials(counterpartName) }}</span>
                  </span>
                  <article
                    class="campaign-messages__message"
                    :class="{ 'campaign-messages__message--mine': isMessageMine(message) }"
                  >
                    <p>{{ message.body }}</p>
                    <div class="campaign-messages__message-meta">
                      <time
                        :datetime="message.createdAt"
                        :title="messageTimeDescription(message.createdAt)"
                        :aria-label="messageTimeDescription(message.createdAt)"
                        aria-live="off"
                      >{{ chatTime.clock(message.createdAt) }}</time>
                      <span v-if="isMessageMine(message)" class="campaign-messages__receipt" :class="{ 'campaign-messages__receipt--seen': message.readAt }" :title="t(message.readAt ? 'campaignChat.seen' : 'campaignChat.sent')" :aria-label="t(message.readAt ? 'campaignChat.seen' : 'campaignChat.sent')">
                        <CheckCheck v-if="message.readAt" :size="15" aria-hidden="true" />
                        <Check v-else :size="15" aria-hidden="true" />
                      </span>
                    </div>
                  </article>
                  <span
                    v-if="isMessageMine(message)"
                    class="campaign-messages__avatar campaign-messages__avatar--message"
                    :class="{ 'campaign-messages__avatar--grouped': chatMessagesAreGrouped(message, day.messages[index + 1]) }"
                  >
                    <img v-if="currentAvatar" :src="currentAvatar" alt="" loading="lazy">
                    <span v-else>{{ initials(currentDisplayName) || 'W' }}</span>
                  </span>
                </div>
              </template>
            </div>
          </div>
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
        </template>

        <p
          v-else-if="pendingStart && currentUser && currentUser.accountType !== 'company'"
          class="campaign-messages__empty"
        >{{ t('campaignChat.companyOnlyStart') }}</p>
        <div v-else class="campaign-messages__empty">
          <MessageCircle :size="30" stroke-width="1.4" aria-hidden="true" />
          <p>{{ t('campaignChat.selectConversation') }}</p>
        </div>

        <button v-if="selectedThread && unseenNewMessages" type="button" class="campaign-messages__new-messages" @click="scrollToLatestMessage">{{ t('campaignChat.newMessages', { count: unseenNewMessages }) }}</button>
        <form v-form-validation v-if="selectedThread || (pendingStart && pendingDetails && currentUser?.accountType === 'company')" class="campaign-messages__composer" @submit.prevent="sendMessage">
          <p v-if="campaignChatClosed" id="campaign-chat-closed-notice" class="campaign-messages__closed-notice" role="status">{{ t(campaignChatNotice) }}</p>
          <label class="campaign-messages__composer-field">
            <span class="sr-only">{{ t(selectedThread ? 'campaignChat.replyPlaceholder' : 'campaignChat.firstMessage') }}</span>
            <input
              ref="composerInput"
              v-model="draft"
              :disabled="campaignChatClosed"
              @focus="handleComposerFocus"
              @blur="handleComposerBlur"
              @input="scheduleViewportUpdate"
              type="text"
              maxlength="2000"
              required
              autocomplete="off"
              enterkeyhint="send"
              :placeholder="t(selectedThread ? 'campaignChat.replyPlaceholder' : 'campaignChat.firstMessage')"
              :aria-describedby="campaignChatClosed ? 'campaign-chat-closed-notice' : 'campaign-message-enter-hint'"
            >
            <span id="campaign-message-enter-hint" class="sr-only">
              {{ t('campaignChat.messageHint') }}
            </span>
          </label>
          <button
            class="campaign-messages__send"
            type="submit"
            @pointerdown="preserveComposerFocus"
            @mousedown="preserveComposerFocus"
            :aria-label="sending ? t('campaignChat.sending') : t('campaignChat.send')"
            :title="sending ? t('campaignChat.sending') : t('campaignChat.send')"
            :disabled="!canSend"
          >
            <Send :size="16" aria-hidden="true" />
          </button>
        </form>
      </section>

      <aside
        v-if="selectedCampaign && campaignDetailsReady"
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
              <CampaignHiringProgress :campaign="selectedCampaign" />
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
                  <dd>{{ campaignPlace(selectedCampaign, locale) }}</dd>
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

<style lang="scss" src="../scss/views/MessagesView.scss"></style>
