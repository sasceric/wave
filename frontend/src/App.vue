<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isNavigationFailure, NavigationFailureType, RouterView, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bell, Building2, ChevronRight, Download, House, LogOut, Menu, Megaphone, MessageCircle, UserRound, UsersRound, X } from '@lucide/vue'
import { registerSW } from 'virtual:pwa-register'
import AccountSidebar from './components/account/AccountSidebar.vue'
import LanguageSwitcher from './components/shared/LanguageSwitcher.vue'
import HeaderCreatorSearch from './components/shared/HeaderCreatorSearch.vue'
import LocalizedLink from './components/shared/LocalizedLink.vue'
import WaveLogo from './components/shared/WaveLogo.vue'
import WaveWordmark from './components/shared/WaveWordmark.vue'
import FooterNewsletterSignup from './components/shared/FooterNewsletterSignup.vue'
import SuccessModal from './components/shared/SuccessModal.vue'
import CookieConsentBanner from './components/shared/CookieConsentBanner.vue'
import NotificationsPanel from './components/shared/NotificationsPanel.vue'
import { currentUser, setCurrentUser } from './composables/useCurrentUser'
import { useAdminWorker } from './composables/useAdminWorker'
import { unreadMessageCount } from './composables/useUnreadMessages'
import { mobileAccountSidebarOpen } from './composables/useMobileAccountSidebar'
import { setLocale } from './i18n'
import { apiGet, apiRequest } from './lib/api'
import { startInboxSync } from './lib/inboxSync'
import { updateAppBadge } from './lib/appBadge'
import { notificationDestination, startNotificationNavigation } from './lib/notificationNavigation'
import { updateSeo } from './lib/seo'
import { trackPageView } from './lib/privacyMetrics'
import { localizedPath } from './routePaths'

const route = useRoute()
const router = useRouter()
useAdminWorker(currentUser, route)
const { locale, t } = useI18n()
const currentYear = new Date().getFullYear()
const newsletterEmail = ref('')
const newsletterError = ref('')
const newsletterSubmitting = ref(false)
const newsletterSuccessOpen = ref(false)
const footerMobileBreakpoint = window.matchMedia('(max-width: 760px)')
const isMobileFooter = ref(footerMobileBreakpoint.matches)
const mobileFooterSectionsOpen = ref({
  explore: false,
  legal: false,
  contact: false,
  newsletter: false,
})
const cookieConsentBanner = ref(null)
const mobileChromeHidden = ref(false)
const mobileMenu = ref(null)
const authMenuOpen = ref(false)
const authMenu = ref(null)
const authMenuTrigger = ref(null)
const authMenuError = ref('')
const failedAccountImage = ref('')
const accountName = computed(() => currentUser.value?.profile?.displayName || currentUser.value?.profile?.name || currentUser.value?.name || currentUser.value?.email || t('app.account'))
const accountImage = computed(() => currentUser.value?.profile?.avatarUrl || currentUser.value?.profile?.logoUrl || '')
const signingOut = ref(false)
const notificationsMenuOpen = ref(false)
const notificationsMenu = ref(null)
const notifications = ref([])
const unreadNotificationTotal = ref(0)
const notificationsLoaded = ref(false)
const notificationsRemaining = ref(0)
const notificationsCursor = ref(null)
const loadingMoreNotifications = ref(false)
const enablingNotifications = ref(false)
const unreadMessagesLoaded = ref(false)
const notificationsError = ref('')
const markingAllRead = ref(false)
const optimisticUnreadNotificationIds = ref([])
const pushConfig = ref({ enabled: false, publicKey: '' })
const pushSupported = ref(false)
const pushSubscribed = ref(false)
const pushStatus = ref('')
const pushBusy = ref(false)
const updateAvailable = ref(false)
const updateInProgress = ref(false)
const updateError = ref('')
const installHelpVisible = ref(false)
const installAvailable = ref(false)
const installHint = computed(() => isIosDevice() ? t('app.installIosHint') : t('app.installHint'))
const unreadNotificationCount = computed(() => currentUser.value?.notificationsEnabled === false ? 0 : (
  unreadNotificationTotal.value
  + optimisticUnreadNotificationIds.value.length
))
const canShowInstallButton = computed(() => (
  installAvailable.value
  || (isIosDevice() && !window.matchMedia('(display-mode: standalone)').matches)
))
const isMobileAccountNavigationPage = computed(() => Boolean(
  currentUser.value
  && (route.meta.accountSection
    || route.meta.adminSection
    || (route.meta.routeName === 'moderation'
      && (currentUser.value.isModerator || currentUser.value.isAdmin))),
))
const mobileNavigationOpen = computed(() => mobileAccountSidebarOpen.value)
const hideSiteFooter = computed(() => Boolean(
  route.meta.accountSection
  || route.meta.adminSection
  || (route.meta.routeName === 'moderation'
    && (currentUser.value?.isModerator || currentUser.value?.isAdmin)),
))
let realtimeSource = null
let realtimeRetryTimer = null
let realtimeConnecting = false
let realtimeConnectionVersion = 0
let realtimeLastEventId = ''
let inboxSync = null
let stopNotificationNavigation = null
let notificationsRequestVersion = 0
let unreadMessagesRequestVersion = 0
const seenRealtimeNotificationIds = new Set()
let installPrompt = null
let updateServiceWorker = null
let serviceWorkerRegistration = null
let serviceWorkerUpdateTimer = null
let previousScrollY = 0
let scrollDirection = 0
let scrollDirectionDistance = 0
let scrollFrame = null
watch(locale, setLocale)
watch(
  () => [
    currentUser.value?.id,
    notificationsLoaded.value,
    unreadMessagesLoaded.value,
    unreadNotificationCount.value,
    unreadMessageCount.value,
  ],
  refreshAppBadge,
)
watch(() => route.fullPath, () => {
  mobileAccountSidebarOpen.value = false
  authMenuOpen.value = false
  notificationsMenuOpen.value = false
  resetMobileChrome()
  if (route.meta.routeName === 'messages' && currentUser.value) {
    void loadNotifications()
    void loadUnreadMessages()
  }
})
watch(() => route.path, (path) => {
  if (
    !route.meta.accountSection
    && !route.meta.adminSection
    && route.name !== 'legacy-not-found'
    && !['messages', 'verify-email', 'reset-password', 'moderation', 'not-found'].includes(route.meta.routeName)
  ) {
    trackPageView(path)
  }
})
watch(currentUser, (user, previousUser) => {
  mobileAccountSidebarOpen.value = false
  authMenuOpen.value = false
  if (user?.id !== previousUser?.id) {
    if (user && previousUser?.id) void updateAppBadge(0)
    closeRealtime()
    realtimeLastEventId = ''
    seenRealtimeNotificationIds.clear()
    notifications.value = []
    unreadNotificationTotal.value = 0
    notificationsLoaded.value = false
    notificationsRemaining.value = 0
    notificationsCursor.value = null
    loadingMoreNotifications.value = false
    unreadMessagesLoaded.value = false
    optimisticUnreadNotificationIds.value = []
    unreadMessageCount.value = 0
    notificationsRequestVersion += 1
    unreadMessagesRequestVersion += 1
  }
  if (!user) {
    mobileAccountSidebarOpen.value = false
    notificationsMenuOpen.value = false
    notifications.value = []
    notificationsError.value = ''
    optimisticUnreadNotificationIds.value = []
    unreadMessageCount.value = 0
    seenRealtimeNotificationIds.clear()
    notificationsRequestVersion += 1
    unreadMessagesRequestVersion += 1
    pushSubscribed.value = false
    pushStatus.value = ''
    void updateAppBadge(0)
    closeRealtime()
    return
  }
  void loadNotifications()
  void loadUnreadMessages()
  void connectRealtime()
  void loadPushSettings()
})
watch(
  () => [route.fullPath, locale.value],
  () => {
    const routeName = route.meta.routeName
    const pageKeys = {
      home: ['homeTitle', 'homeDescription'],
      messages: ['privateTitle', 'privateDescription'],
      'admin-tools': ['privateTitle', 'privateDescription'],
      creators: ['creatorsTitle', 'creatorsDescription'],
      'creator-profile': ['creatorsTitle', 'creatorsDescription'],
      companies: ['companiesTitle', 'companiesDescription'],
      'company-profile': ['companiesTitle', 'companiesDescription'],
      campaigns: ['campaignsTitle', 'campaignsDescription'],
      'campaign-detail': ['campaignsTitle', 'campaignsDescription'],
      imprint: ['imprintTitle', 'imprintDescription'],
      'privacy-policy': ['privacyTitle', 'privacyDescription'],
      'cookie-policy': ['cookiesTitle', 'cookiesDescription'],
    }
    const [titleKey, descriptionKey] = pageKeys[routeName] || ['notFoundTitle', 'notFoundDescription']
    const noindex = ['account', 'messages', 'verify-email', 'reset-password', 'moderation', 'admin', 'admin-tools'].includes(routeName)
      || !pageKeys[routeName]

    updateSeo({
      route,
      locale: locale.value,
      title: t(`seo.${titleKey}`),
      description: t(`seo.${descriptionKey}`),
      noindex,
    })
  },
  { immediate: true },
)
onMounted(() => {
  footerMobileBreakpoint.addEventListener('change', handleFooterBreakpointChange)
  stopNotificationNavigation = startNotificationNavigation(async (path) => {
    const failure = await router.push(path)
    return !failure || isNavigationFailure(failure, NavigationFailureType.duplicated)
  })
  previousScrollY = window.scrollY
  inboxSync = startInboxSync(async () => {
    const userId = currentUser.value?.id
    if (!userId) return
    await Promise.all([loadNotifications(), loadUnreadMessages()])
    if (currentUser.value?.id === userId) {
      window.dispatchEvent(new Event('wave:inbox-refresh'))
    }
  })
  if (!import.meta.env.DEV && 'serviceWorker' in navigator) {
    updateServiceWorker = registerSW({
      immediate: true,
      onNeedRefresh() {
        updateAvailable.value = true
        updateError.value = ''
      },
      onRegisteredSW(scriptUrl, registration) {
        void scriptUrl
        serviceWorkerRegistration = registration ?? null
      },
      onRegisterError(cause) {
        console.error('Unable to register the Wave service worker.', cause)
      },
    })
    document.addEventListener('visibilitychange', checkForWaveUpdate)
    serviceWorkerUpdateTimer = window.setInterval(checkForWaveUpdate, 30 * 60 * 1000)
  }
  window.addEventListener('scroll', handleMobileScroll, { passive: true })
  window.addEventListener('resize', resetMobileChrome)
  window.addEventListener('beforeinstallprompt', captureInstallPrompt)
  window.addEventListener('appinstalled', onAppInstalled)
  window.addEventListener('wave:conversations-updated', handleConversationsUpdated)
  window.addEventListener('wave:open-notifications', openSidebarNotifications)
  document.addEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeNotificationsOnOutsideClick)
})
onBeforeUnmount(() => {
  footerMobileBreakpoint.removeEventListener('change', handleFooterBreakpointChange)
  stopNotificationNavigation?.()
  inboxSync?.stop()
  closeRealtime()
  window.removeEventListener('scroll', handleMobileScroll)
  window.removeEventListener('resize', resetMobileChrome)
  if (scrollFrame !== null) window.cancelAnimationFrame(scrollFrame)
  window.removeEventListener('beforeinstallprompt', captureInstallPrompt)
  window.removeEventListener('appinstalled', onAppInstalled)
  window.removeEventListener('wave:conversations-updated', handleConversationsUpdated)
  window.removeEventListener('wave:open-notifications', openSidebarNotifications)
  document.removeEventListener('visibilitychange', checkForWaveUpdate)
  if (serviceWorkerUpdateTimer !== null) window.clearInterval(serviceWorkerUpdateTimer)
  document.removeEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeNotificationsOnOutsideClick)
})

function refreshAppBadge() {
  if (currentUser.value && notificationsLoaded.value && unreadMessagesLoaded.value) {
    void updateAppBadge(unreadNotificationCount.value + unreadMessageCount.value)
  }
}

async function loadNotifications() {
  const userId = currentUser.value?.id
  if (!userId) return
  const requestVersion = ++notificationsRequestVersion
  loadingMoreNotifications.value = false
  const pendingIds = new Set(optimisticUnreadNotificationIds.value)
  try {
    const response = await apiGet('/me/notifications?limit=5')
    if (
      currentUser.value?.id !== userId
      || requestVersion !== notificationsRequestVersion
    ) {
      return
    }
    const refreshed = new Map(response.data.map(item => [item.id, item]))
    // Retain older pages only while the refreshed head overlaps the loaded range.
    // Otherwise five or more arrivals could leave an unseen gap above the old cursor.
    if (notifications.value.some(item => refreshed.has(item.id))) {
      for (const item of notifications.value) if (!refreshed.has(item.id)) refreshed.set(item.id, item)
    }
    notifications.value = [...refreshed.values()].sort((a, b) => b.id - a.id)
    notificationsRemaining.value = Math.max(0, (response.meta?.remaining ?? 0) - (notifications.value.length - response.data.length))
    notificationsCursor.value = notificationsRemaining.value > 0 ? notifications.value.at(-1)?.id : null
    unreadNotificationTotal.value = response.unreadCount ?? response.data.filter((item) => !item.readAt).length
    notificationsLoaded.value = true
    const loadedIds = new Set(response.data.map((notification) => notification.id))
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => !loadedIds.has(id) && !pendingIds.has(id),
    )
    notificationsError.value = ''
  } catch (cause) {
    if (requestVersion !== notificationsRequestVersion) return
    if (cause.status === 401) {
      setCurrentUser(null)
      return
    }
    notificationsError.value = cause.message
  }
}

async function loadMoreNotifications() {
  if (loadingMoreNotifications.value || !notificationsCursor.value) return
  const userId = currentUser.value?.id
  const version = notificationsRequestVersion
  const cursor = notificationsCursor.value
  loadingMoreNotifications.value = true
  try {
    const response = await apiGet(`/me/notifications?limit=5&before=${cursor}`)
    if (currentUser.value?.id !== userId || version !== notificationsRequestVersion) return
    const items = new Map(notifications.value.map(item => [item.id, item]))
    for (const item of response.data) items.set(item.id, item)
    notifications.value = [...items.values()].sort((a, b) => b.id - a.id)
    notificationsRemaining.value = response.meta?.remaining ?? 0
    notificationsCursor.value = response.meta?.nextCursor ?? null
    notificationsError.value = ''
  } catch (cause) {
    if (currentUser.value?.id === userId && version === notificationsRequestVersion) notificationsError.value = cause.message
  } finally {
    if (currentUser.value?.id === userId && version === notificationsRequestVersion) loadingMoreNotifications.value = false
  }
}

async function enableAccountNotifications() {
  if (enablingNotifications.value || !currentUser.value) return
  const userId = currentUser.value.id
  enablingNotifications.value = true
  notificationsError.value = ''
  try {
    const response = await apiRequest('/me/notifications/settings', { method: 'PUT', body: { enabled: true } })
    if (currentUser.value?.id !== userId) return
    setCurrentUser(response.data)
    if (pushSupported.value && pushConfig.value.enabled && !pushSubscribed.value) await togglePushNotifications()
  } catch (cause) {
    notificationsError.value = cause.message
  } finally {
    enablingNotifications.value = false
  }
}

function closeNotifications() {
  notificationsMenuOpen.value = false
  notificationsMenu.value?.querySelector('.header-notifications__trigger')?.focus()
}

async function loadUnreadMessages() {
  const userId = currentUser.value?.id
  if (!userId) {
    unreadMessageCount.value = 0
    return
  }
  const requestVersion = ++unreadMessagesRequestVersion

  try {
    const response = await apiGet('/me/inbox/unread')
    if (
      currentUser.value?.id !== userId
      || requestVersion !== unreadMessagesRequestVersion
    ) {
      return
    }
    unreadMessageCount.value = response.unreadCount || 0
    unreadMessagesLoaded.value = true
  } catch (cause) {
    if (requestVersion !== unreadMessagesRequestVersion) return
    if (cause.status === 401) {
      setCurrentUser(null)
      return
    }
    console.error('Unable to load the unread Wave message count.', cause)
  }
}

function handleConversationsUpdated() {
  void loadUnreadMessages()
}

function handleMobileScroll() {
  if (scrollFrame !== null) return
  scrollFrame = window.requestAnimationFrame(() => {
    scrollFrame = null
    const currentScrollY = Math.max(0, window.scrollY)
    const delta = currentScrollY - previousScrollY
    previousScrollY = currentScrollY
    const isAtBottom = currentScrollY + window.innerHeight >= document.documentElement.scrollHeight - 24

    if (!window.matchMedia('(max-width: 760px)').matches || currentScrollY <= 20 || isAtBottom) {
      mobileChromeHidden.value = false
      scrollDirection = 0
      scrollDirectionDistance = 0
      return
    }

    const direction = Math.sign(delta)
    if (direction === 0) return
    if (direction !== scrollDirection) {
      scrollDirection = direction
      scrollDirectionDistance = 0
    }
    scrollDirectionDistance += Math.abs(delta)
    if (scrollDirectionDistance < 12) return

    mobileChromeHidden.value = direction > 0
    scrollDirectionDistance = 0
  })
}

function resetMobileChrome() {
  if (scrollFrame !== null) {
    window.cancelAnimationFrame(scrollFrame)
    scrollFrame = null
  }
  previousScrollY = window.scrollY
  scrollDirection = 0
  scrollDirectionDistance = 0
  mobileChromeHidden.value = false
  if (!window.matchMedia('(max-width: 760px)').matches) {
    mobileAccountSidebarOpen.value = false
  }
}

function toggleMobileNavigation() {
  mobileAccountSidebarOpen.value = !mobileAccountSidebarOpen.value
}

async function connectRealtime() {
  const userId = currentUser.value?.id
  if (!userId || realtimeSource || realtimeConnecting) return
  const connectionVersion = ++realtimeConnectionVersion
  realtimeConnecting = true
  if (realtimeRetryTimer !== null) {
    window.clearTimeout(realtimeRetryTimer)
    realtimeRetryTimer = null
  }

  try {
    const response = await apiGet('/me/realtime')
    if (currentUser.value?.id !== userId || connectionVersion !== realtimeConnectionVersion) return
    const { hubUrl, topic } = response.data
    const url = new URL(hubUrl)
    url.searchParams.append('match', topic)
    if (realtimeLastEventId) url.searchParams.set('lastEventID', realtimeLastEventId)
    const source = new EventSource(url, { withCredentials: true })
    realtimeSource = source
    source.onopen = () => {
      if (realtimeSource === source) void inboxSync?.refresh()
    }
    source.onmessage = (event) => {
      if (realtimeSource !== source || currentUser.value?.id !== userId) return
      if (event.lastEventId) realtimeLastEventId = event.lastEventId
      try {
        const update = JSON.parse(event.data)
        if (update.type === 'chat_read') {
          window.dispatchEvent(new CustomEvent('wave:realtime', { detail: update }))
          return
        }
        if (update.type === 'chat_message') {
          const key = `inquiry-${update.inquiryId}-message-${update.message?.id}`
          if (seenRealtimeNotificationIds.has(key)) return
          seenRealtimeNotificationIds.add(key)
          if (seenRealtimeNotificationIds.size > 500) seenRealtimeNotificationIds.delete(seenRealtimeNotificationIds.values().next().value)
        } else if (hasSeenRealtimeNotification(update.notificationId)) return

        if (update.notificationType !== 'chat_message') {
          addOptimisticUnreadNotification(update.notificationId)
          void loadNotifications()
        }
        const isOpenConversation = (
          update.notificationType === 'chat_message'
          && route.meta.routeName === 'messages'
          && (update.inquiryId !== undefined
            ? Number(route.query.inquiry) === Number(update.inquiryId)
            : Number(route.query.conversation) === Number(update.conversationId))
          && document.visibilityState === 'visible'
        )
        if (update.notificationType === 'chat_message') {
          const senderId = update.message?.senderId
          if (senderId === undefined || senderId !== currentUser.value?.id) {
            unreadMessageCount.value += 1
          }
        }
        if (!isOpenConversation) {
          void loadUnreadMessages()
        }
        window.dispatchEvent(new CustomEvent('wave:realtime', { detail: update }))
      } catch (cause) {
        console.error('Unable to read a Wave realtime update.', cause)
      }
    }
    source.onerror = () => {
      if (realtimeSource !== source) return
      source.close()
      realtimeSource = null
      scheduleRealtimeRetry()
    }
  } catch (cause) {
    if (connectionVersion !== realtimeConnectionVersion) return
    if (cause.status !== 401) {
      console.error('Unable to connect to Wave realtime updates.', cause)
    }
    scheduleRealtimeRetry()
  } finally {
    if (connectionVersion === realtimeConnectionVersion) realtimeConnecting = false
  }
}

function hasSeenRealtimeNotification(notificationId) {
  const id = Number(notificationId)
  if (!Number.isInteger(id) || id < 1 || seenRealtimeNotificationIds.has(id)) {
    return true
  }

  seenRealtimeNotificationIds.add(id)
  if (seenRealtimeNotificationIds.size > 500) {
    seenRealtimeNotificationIds.delete(seenRealtimeNotificationIds.values().next().value)
  }

  return false
}

function addOptimisticUnreadNotification(notificationId) {
  const id = Number(notificationId)
  if (
    Number.isInteger(id)
    && id > 0
    && !notifications.value.some((notification) => notification.id === id)
    && !optimisticUnreadNotificationIds.value.includes(id)
  ) {
    optimisticUnreadNotificationIds.value = [
      ...optimisticUnreadNotificationIds.value,
      id,
    ]
  }
}

function closeRealtime() {
  realtimeConnectionVersion += 1
  realtimeConnecting = false
  if (realtimeRetryTimer !== null) {
    window.clearTimeout(realtimeRetryTimer)
    realtimeRetryTimer = null
  }
  realtimeSource?.close()
  realtimeSource = null
}

function scheduleRealtimeRetry() {
  if (currentUser.value && realtimeRetryTimer === null) {
    realtimeRetryTimer = window.setTimeout(() => {
      realtimeRetryTimer = null
      void connectRealtime()
    }, 5000)
  }
}

async function loadPushSettings() {
  pushSupported.value = 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window
  if (!pushSupported.value) {
    pushStatus.value = 'pushUnsupported'
    return
  }

  try {
    const response = await apiGet('/me/push/config')
    pushConfig.value = response.data
    if (!pushConfig.value.enabled) {
      pushStatus.value = 'pushNotConfigured'
      return
    }
    const registration = await navigator.serviceWorker.ready
    const subscription = await registration.pushManager.getSubscription()
    pushSubscribed.value = subscription !== null
    pushStatus.value = subscription ? 'pushEnabled' : ''
  } catch (cause) {
    pushStatus.value = cause.status === 401 ? '' : 'pushError'
  }
}

async function togglePushNotifications() {
  if (pushBusy.value) return
  pushBusy.value = true
  pushStatus.value = ''
  if (!pushSupported.value || !pushConfig.value.enabled) {
    pushBusy.value = false
    return
  }

  try {
    const registration = await navigator.serviceWorker.ready
    const existing = await registration.pushManager.getSubscription()
    if (existing) {
      await apiRequest('/me/push-subscriptions', {
        method: 'DELETE',
        body: { endpoint: existing.endpoint },
      })
      await existing.unsubscribe()
      pushSubscribed.value = false
      pushStatus.value = 'pushDisabled'
      return
    }

    const permission = await Notification.requestPermission()
    if (permission !== 'granted') {
      pushStatus.value = permission === 'denied' ? 'pushDenied' : 'pushPermissionNeeded'
      return
    }
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: decodeApplicationServerKey(pushConfig.value.publicKey),
    })
    try {
      await apiRequest('/me/push-subscriptions', {
        method: 'POST',
        body: {
          endpoint: subscription.endpoint,
          keys: subscription.toJSON().keys,
        },
      })
    } catch (cause) {
      await subscription.unsubscribe()
      throw cause
    }
    pushSubscribed.value = true
    pushStatus.value = 'pushEnabled'
    refreshAppBadge()
  } catch (cause) {
    pushStatus.value = 'pushError'
    notificationsError.value = cause.message
  } finally {
    pushBusy.value = false
  }
}

function decodeApplicationServerKey(value) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(window.atob(base64), (character) => character.charCodeAt(0))
}

function isIosDevice() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

async function openNewsletterSignup({ website = '' } = {}) {
  if (newsletterSubmitting.value) return
  newsletterError.value = ''
  newsletterSubmitting.value = true

  try {
    await apiRequest('/newsletter/subscribers', {
      method: 'POST',
      locale: locale.value,
      body: {
        email: newsletterEmail.value.trim(),
        website,
      },
    })
    newsletterEmail.value = ''
    newsletterSuccessOpen.value = true
  } catch (cause) {
    newsletterError.value = cause.message || t('app.footerNewsletterError')
  } finally {
    newsletterSubmitting.value = false
  }
}

function handleFooterBreakpointChange(event) {
  isMobileFooter.value = event.matches
  mobileFooterSectionsOpen.value = {
    explore: false,
    legal: false,
    contact: false,
    newsletter: false,
  }
}

function syncFooterSection(section, event) {
  if (!isMobileFooter.value) return
  mobileFooterSectionsOpen.value[section] = event.currentTarget.open
}

function scrollToPageTop() {
  const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
  window.scrollTo({ top: 0, behavior })
}

function captureInstallPrompt(event) {
  event.preventDefault()
  installPrompt = event
  installAvailable.value = true
}

function onAppInstalled() {
  installPrompt = null
  installAvailable.value = false
  installHelpVisible.value = false
  refreshAppBadge()
}

function checkForWaveUpdate() {
  if (!serviceWorkerRegistration || document.visibilityState !== 'visible') {
    return
  }

  void serviceWorkerRegistration.update().catch((cause) => {
    console.error('Unable to check for a Wave update.', cause)
  })
}

async function installWave() {
  if (!installPrompt) {
    installHelpVisible.value = !installHelpVisible.value
    return
  }
  installPrompt.prompt()
  await installPrompt.userChoice
  installPrompt = null
  installAvailable.value = false
}

async function applyWaveUpdate() {
  if (!updateServiceWorker || updateInProgress.value) {
    return
  }

  updateInProgress.value = true
  updateError.value = ''
  try {
    await updateServiceWorker(true)
  } catch (cause) {
    console.error('Unable to apply the available Wave update.', cause)
    updateError.value = t('app.updateFailed')
    updateInProgress.value = false
  }
}

async function markAllNotificationsRead() {
  markingAllRead.value = true
  notificationsError.value = ''
  const pendingIds = new Set(optimisticUnreadNotificationIds.value)
  const userId = currentUser.value?.id
  try {
    await apiRequest('/me/notifications/read-all', { method: 'POST', body: {} })
    if (currentUser.value?.id !== userId) return
    unreadNotificationTotal.value = 0
    notifications.value = notifications.value.map((notification) => (
      notification.readAt ? notification : { ...notification, readAt: new Date().toISOString() }
    ))
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => !pendingIds.has(id),
    )
    void loadNotifications()
  } catch (cause) {
    notificationsError.value = cause.message
  } finally {
    markingAllRead.value = false
  }
}

async function openNotification(notification) {
  notificationsError.value = ''
  const userId = currentUser.value?.id
  try {
    const response = await apiRequest(`/me/notifications/${notification.id}/read`, {
      method: 'POST',
      body: {},
    })
    if (currentUser.value?.id !== userId) return
    const index = notifications.value.findIndex((item) => item.id === notification.id)
    if (index !== -1 && !notifications.value[index].readAt) {
      unreadNotificationTotal.value = Math.max(0, unreadNotificationTotal.value - 1)
    }
    if (index !== -1) notifications.value.splice(index, 1, response.data)
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => id !== notification.id,
    )
    notificationsMenuOpen.value = false
    void loadNotifications()
    const destination = notificationDestination(notification)
    await router.push({ path: localizedPath(destination.name, locale.value), query: destination.query })
  } catch (cause) {
    notificationsError.value = cause.message
  }
}

async function toggleAuthMenu() {
  resetMobileChrome()
  authMenuOpen.value = !authMenuOpen.value
  notificationsMenuOpen.value = false
  if (authMenuOpen.value) {
    await nextTick()
    authMenu.value?.querySelector('.header-user-menu__item')?.focus()
  }
}

function toggleNotifications() {
  resetMobileChrome()
  authMenuOpen.value = false
  notificationsMenuOpen.value = !notificationsMenuOpen.value
  if (notificationsMenuOpen.value) void loadNotifications()
}

function closeMobileMenuOnOutsideClick(event) {
  if (!(event.target instanceof Node) || mobileMenu.value?.contains(event.target) || event.target.closest?.('.account-sidebar')) {
    return
  }
  mobileAccountSidebarOpen.value = false
}

function closeAuthMenuOnOutsideClick(event) {
  if (authMenu.value && event.target instanceof Node && !authMenu.value.contains(event.target)) {
    authMenuOpen.value = false
  }
}

function closeNotificationsOnOutsideClick(event) {
  if (notificationsMenu.value && event.target instanceof Node && !notificationsMenu.value.contains(event.target)) {
    notificationsMenuOpen.value = false
  }
}

function closeAuthMenuAndRestoreFocus() {
  authMenuOpen.value = false
  authMenuTrigger.value?.focus()
}

function openSidebarNotifications() {
  if (!currentUser.value) return
  mobileAccountSidebarOpen.value = false
  resetMobileChrome()
  notificationsMenuOpen.value = true
  void loadNotifications()
}

async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  authMenuError.value = ''
  try {
    await apiRequest('/auth/logout', { method: 'POST', body: {} })
    setCurrentUser(null)
    authMenuOpen.value = false
    await router.replace({
      path: localizedPath('account', locale.value),
      query: { mode: 'login' },
    })
  } catch (cause) {
    authMenuError.value = cause.message
  } finally {
    signingOut.value = false
  }
}

</script>

<template>
  <div
    class="site-shell"
    :class="{
      'site-shell--mobile-chrome-hidden': mobileChromeHidden,
      'site-shell--account': hideSiteFooter,
      'site-shell--footer-visible': !hideSiteFooter,
      'site-shell--registration': route.meta.routeName === 'account'
        && route.query.mode === 'register'
        && !currentUser,
      'site-shell--auth-splash': route.meta.routeName === 'account'
        && route.query.mode !== 'register'
        && route.query.mode !== 'reset'
        && !currentUser,
    }"
  >
    <header class="site-header">
      <div class="site-header__inner">
        <WaveWordmark :aria-label="t('app.homeAria')" />
        <nav class="main-nav" :aria-label="t('app.mainNavigation')">
          <LocalizedLink
            to="/creators"
            :class="{ 'is-active': ['creators', 'creator-profile'].includes(route.meta.routeName) }"
          >
            {{ t('app.creators') }}
          </LocalizedLink>
          <LocalizedLink
            to="/companies"
            :class="{ 'is-active': ['companies', 'company-profile'].includes(route.meta.routeName) }"
          >
            {{ t('app.companies') }}
          </LocalizedLink>
          <LocalizedLink
            to="/campaigns"
            :class="{ 'is-active': ['campaigns', 'campaign-detail'].includes(route.meta.routeName) }"
          >
            {{ t('app.campaigns') }}
          </LocalizedLink>
        </nav>
        <HeaderCreatorSearch />
        <div class="site-header__actions">
          <div
            v-if="currentUser"
            ref="notificationsMenu"
            class="header-notifications"
            @keydown.esc.stop.prevent="closeNotifications"
          >
            <button
              class="header-notifications__trigger"
              type="button"
              :aria-label="unreadNotificationCount ? `${t('app.notifications')} (${unreadNotificationCount})` : t('app.notifications')"
              aria-haspopup="true"
              aria-controls="header-notifications-panel"
              :aria-expanded="notificationsMenuOpen"
              @click="toggleNotifications"
            >
              <Bell :size="19" stroke-width="1.8" aria-hidden="true" />
              <span v-if="unreadNotificationCount" class="header-notifications__badge" aria-hidden="true">
              </span>
            </button>
            <NotificationsPanel
              v-if="notificationsMenuOpen"
              :items="notifications"
              :loaded="notificationsLoaded"
              :disabled="currentUser?.notificationsEnabled === false"
              :mobile="isMobileFooter"
              :error="notificationsError"
              :unread="unreadNotificationCount"
              :marking-read="markingAllRead"
              :enabling="enablingNotifications || pushBusy"
              :loading-more="loadingMoreNotifications"
              :remaining="notificationsRemaining"
              @close="closeNotifications"
              @read-all="markAllNotificationsRead"
              @open="openNotification"
              @enable="enableAccountNotifications"
              @load-more="loadMoreNotifications"
            />
          </div>
          <LocalizedLink
            v-if="currentUser"
            class="header-messages__trigger"
            :class="{ 'is-active': route.meta.routeName === 'messages' }"
            :to="{ name: 'messages' }"
            :aria-label="unreadMessageCount ? `${t('app.messages')} (${unreadMessageCount})` : t('app.messages')"
            :title="t('app.messages')"
          >
            <MessageCircle :size="19" stroke-width="1.8" aria-hidden="true" />
            <span v-if="unreadMessageCount" class="header-messages__badge" aria-hidden="true">
              {{ unreadMessageCount > 99 ? '99+' : unreadMessageCount }}
            </span>
          </LocalizedLink>
          <div v-if="canShowInstallButton" class="header-install">
            <button
              class="header-install__trigger"
              type="button"
              :aria-label="t('app.installApp')"
              :aria-describedby="installHelpVisible ? 'install-wave-tooltip' : undefined"
              :aria-expanded="installHelpVisible"
              :title="installHint"
              @click="installWave"
            >
              <Download :size="19" stroke-width="1.8" aria-hidden="true" />
            </button>
            <span v-if="installHelpVisible" id="install-wave-tooltip" class="header-install__tooltip" role="tooltip">
              {{ installHint }}
            </span>
          </div>
          <div
            ref="authMenu"
            class="header-user-menu"
            @keydown.esc.stop.prevent="closeAuthMenuAndRestoreFocus"
          >
            <button
              ref="authMenuTrigger"
              class="header-user-menu__trigger"
              :class="{ 'header-user-menu__trigger--signed-in': currentUser }"
              type="button"
              :aria-label="t('app.account')"
              aria-haspopup="true"
              aria-controls="header-user-menu-panel"
              :aria-expanded="authMenuOpen"
              @click="toggleAuthMenu"
            >
              <template v-if="currentUser">
                <span class="header-user-menu__avatar">
                  <img v-if="accountImage && failedAccountImage !== accountImage" :src="accountImage" alt="" @error="failedAccountImage = accountImage" />
                  <WaveLogo v-else mark />
                </span>
                <span class="header-user-menu__name">{{ accountName }}</span>
              </template>
              <UserRound v-else :size="19" stroke-width="1.8" aria-hidden="true" />
            </button>
            <div v-if="authMenuOpen && currentUser" class="header-user-menu__backdrop" aria-hidden="true" @click="closeAuthMenuAndRestoreFocus"></div>
            <div v-if="authMenuOpen" id="header-user-menu-panel" class="header-user-menu__panel">
              <template v-if="currentUser">
                <div class="header-user-menu__identity">
                  <span class="header-user-menu__avatar header-user-menu__avatar--large">
                    <img v-if="accountImage && failedAccountImage !== accountImage" :src="accountImage" alt="" @error="failedAccountImage = accountImage" />
                    <WaveLogo v-else mark />
                  </span>
                  <div><h2>{{ accountName }}</h2><p>{{ currentUser.email }}</p></div>
                </div>
                <LocalizedLink class="header-user-menu__item" :to="{ name: 'account' }" @click="authMenuOpen = false">
                  <UserRound :size="23" stroke-width="1.8" aria-hidden="true" />
                  {{ t('app.myProfile') }}
                  <ChevronRight class="header-user-menu__chevron" :size="20" aria-hidden="true" />
                </LocalizedLink>
                <div class="header-user-menu__language">
                  <span>{{ t('app.language') }}</span>
                  <LanguageSwitcher expanded />
                </div>
                <button class="header-user-menu__item header-user-menu__item--sign-out" type="button" :disabled="signingOut" @click="signOut">
                  <LogOut :size="23" stroke-width="1.8" aria-hidden="true" />
                  {{ t('account.signOut') }}
                </button>
                <p v-if="authMenuError" class="header-user-menu__error" role="alert">{{ authMenuError }}</p>
              </template>
              <template v-else>
                <LocalizedLink class="header-user-menu__item" :to="{ name: 'account', query: { mode: 'login' } }" @click="authMenuOpen = false">
                  {{ t('auth.signIn') }}
                </LocalizedLink>
                <LocalizedLink class="header-user-menu__item" :to="{ name: 'account', query: { mode: 'register' } }" @click="authMenuOpen = false">
                  {{ t('auth.register') }}
                </LocalizedLink>
              </template>
            </div>
          </div>
          <div v-if="!currentUser" class="desktop-language-switcher">
            <LanguageSwitcher />
          </div>
        </div>
        <div ref="mobileMenu" class="mobile-menu">
          <button
            class="mobile-menu__toggle"
            type="button"
            :aria-label="mobileNavigationOpen ? t('app.closeMenu') : t('app.openMenu')"
            :aria-expanded="mobileNavigationOpen"
            aria-controls="account-sidebar-menu"
            @click="toggleMobileNavigation"
          >
            <X v-if="mobileNavigationOpen" :size="22" stroke-width="1.8" aria-hidden="true" />
            <Menu v-else :size="22" stroke-width="1.8" aria-hidden="true" />
          </button>
        </div>
      </div>
    </header>

    <AccountSidebar
      v-if="!isMobileAccountNavigationPage"
      :user="currentUser"
      :profile="currentUser?.profile"
      mobile-only
    />

    <main>
      <RouterView />
    </main>

    <footer
      v-if="!hideSiteFooter"
      class="site-footer"
      :class="{ 'site-footer--admin': route.meta.adminSection }"
    >
      <div class="site-footer__inner page-width">
        <div class="site-footer__brand">
          <LocalizedLink to="/" class="site-footer__wordmark">
            <WaveWordmark :light="true" :aria-label="t('app.homeAria')" />
          </LocalizedLink>
          <p>{{ t('app.footerTagline') }}</p>
          <span class="site-footer__description">{{ t('app.footerDescription') }}</span>
          <nav
            class="site-footer__social"
            :aria-label="t('app.socialLinks')"
          >
            <a
              class="site-footer__social-icon site-footer__social-icon--instagram"
              href="https://www.instagram.com/wave.ba.app/"
              target="_blank"
              rel="noopener noreferrer"
              :aria-label="t('app.instagram')"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="3.5" y="3.5" width="17" height="17" rx="5" />
                <circle cx="12" cy="12" r="4" />
                <circle class="site-footer__social-dot" cx="17.7" cy="6.7" r="1" />
              </svg>
            </a>
            <span class="site-footer__social-icon site-footer__social-icon--tiktok" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="M19.4 8.2a7.4 7.4 0 0 1-4.3-1.4v7.1a5.6 5.6 0 1 1-5.6-5.6c.4 0 .8 0 1.2.1v3.1a2.6 2.6 0 1 0 1.8 2.5V2h3.1c.3 2 1.9 3.6 3.9 3.8v2.4Z" />
              </svg>
            </span>
            <a
              class="site-footer__social-icon site-footer__social-icon--facebook"
              href="https://www.facebook.com/app.wave.ba"
              target="_blank"
              rel="noopener noreferrer"
              :aria-label="t('app.facebook')"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M13.5 21v-8h2.7l.4-3.1h-3.1v-2c0-.9.3-1.5 1.6-1.5h1.7V3.6c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.1H7.3V13h2.8v8h3.4Z" />
              </svg>
            </a>
            <span class="site-footer__social-icon site-footer__social-icon--x" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="m4 3 16 18M20 3 4 21" />
              </svg>
            </span>
          </nav>
          <span class="site-footer__social-note">{{ t('app.socialFollow') }}</span>
        </div>

        <FooterNewsletterSignup
          class="site-footer__newsletter-signup--mobile"
          input-id="footer-newsletter-email-mobile"
          v-model="newsletterEmail"
          :loading="newsletterSubmitting"
          :error="newsletterError"
          @submit="openNewsletterSignup"
        />

        <nav class="site-footer__column" :aria-label="t('app.footerExplore')">
          <details
            class="site-footer__accordion"
            :open="!isMobileFooter || mobileFooterSectionsOpen.explore"
            @toggle="syncFooterSection('explore', $event)"
          >
            <summary>
              <span class="site-footer__accordion-title">{{ t('app.footerExplore') }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </summary>
            <div class="site-footer__accordion-content">
              <LocalizedLink to="/creators">{{ t('app.creatorsNav') }}</LocalizedLink>
              <LocalizedLink to="/companies">{{ t('app.companies') }}</LocalizedLink>
              <LocalizedLink to="/campaigns">{{ t('app.campaigns') }}</LocalizedLink>
              <a :href="localizedPath('home', locale) + '#how-it-works'">
                {{ t('home.howItWorks') }}
              </a>
            </div>
          </details>
        </nav>

        <nav class="site-footer__column" :aria-label="t('app.footerLegal')">
          <details
            class="site-footer__accordion"
            :open="!isMobileFooter || mobileFooterSectionsOpen.legal"
            @toggle="syncFooterSection('legal', $event)"
          >
            <summary>
              <span class="site-footer__accordion-title">{{ t('app.footerLegal') }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </summary>
            <div class="site-footer__accordion-content">
              <LocalizedLink :to="{ name: 'imprint' }">{{ t('legal.imprint.title') }}</LocalizedLink>
              <LocalizedLink :to="{ name: 'privacy-policy' }">{{ t('legal.privacy.title') }}</LocalizedLink>
              <LocalizedLink :to="{ name: 'cookie-policy' }">{{ t('legal.cookies.title') }}</LocalizedLink>
              <button type="button" @click="cookieConsentBanner?.open()">
                {{ t('legal.openCookieSettings') }}
              </button>
            </div>
          </details>
        </nav>

        <div class="site-footer__column site-footer__contact">
          <details
            class="site-footer__accordion"
            :open="!isMobileFooter || mobileFooterSectionsOpen.contact"
            @toggle="syncFooterSection('contact', $event)"
          >
            <summary>
              <span class="site-footer__accordion-title">{{ t('app.footerContact') }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </summary>
            <div class="site-footer__accordion-content">
              <p>{{ t('app.footerContactText') }}</p>
              <a class="site-footer__email" href="mailto:info@wave.ba">
                info@wave.ba <span aria-hidden="true">↗</span>
              </a>
              <span class="site-footer__operator">{{ t('app.footerOperator') }}</span>
            </div>
          </details>
        </div>

        <div v-if="!isMobileFooter" class="site-footer__column site-footer__newsletter">
          <details
            class="site-footer__accordion"
            :open="!isMobileFooter || mobileFooterSectionsOpen.newsletter"
            @toggle="syncFooterSection('newsletter', $event)"
          >
            <summary>
              <span class="site-footer__accordion-title">{{ t('app.footerNewsletterTitle') }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </summary>
            <div class="site-footer__accordion-content">
              <FooterNewsletterSignup
                class="site-footer__newsletter-signup--desktop"
                input-id="footer-newsletter-email"
                v-model="newsletterEmail"
                :loading="newsletterSubmitting"
                :error="newsletterError"
                @submit="openNewsletterSignup"
              />
            </div>
          </details>
        </div>
      </div>
      <div class="site-footer__bottom page-width">
        <span class="site-footer__copyright">{{ t('app.copyright', { year: currentYear }) }}</span>
        <nav class="site-footer__quick-links" :aria-label="t('app.footerQuickLinks')">
          <LocalizedLink to="/creators">{{ t('app.footerProfiles') }}</LocalizedLink>
          <LocalizedLink to="/campaigns">{{ t('app.campaigns') }}</LocalizedLink>
          <LocalizedLink :to="{ name: 'account-applications' }">
            {{ t('app.footerApplications') }}
          </LocalizedLink>
          <a
            :href="`mailto:info@wave.ba?subject=${encodeURIComponent(t('app.footerCollaboration'))}`"
          >{{ t('app.footerCollaboration') }}</a>
        </nav>
        <button
          class="site-footer__back-to-top"
          type="button"
          :aria-label="t('app.backToTop')"
          :title="t('app.backToTop')"
          @click="scrollToPageTop"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 19V5M5 12l7-7 7 7" />
          </svg>
        </button>
      </div>
    </footer>
    <SuccessModal
      v-model:open="newsletterSuccessOpen"
      :title="t('app.footerNewsletterSuccessTitle')"
      :message="t('app.footerNewsletterSuccessMessage')"
      :close-label="t('app.footerNewsletterClose')"
    />

    <CookieConsentBanner ref="cookieConsentBanner" />

    <aside
      v-if="updateAvailable"
      class="pwa-update-notice"
      role="status"
      aria-live="polite"
    >
      <div>
        <p>{{ t('app.updateReady') }}</p>
        <p v-if="updateError" class="pwa-update-notice__error" role="alert">{{ updateError }}</p>
      </div>
      <button type="button" :disabled="updateInProgress" @click="applyWaveUpdate">
        {{ t(updateInProgress ? 'app.updating' : 'app.updateNow') }}
      </button>
    </aside>

    <nav class="mobile-bottom-nav" :aria-label="t('app.mainNavigation')">
      <LocalizedLink
        to="/"
        class="mobile-bottom-nav__link"
        :aria-label="t('app.home')"
        :class="{ 'is-active': route.meta.routeName === 'home' }"
      >
        <House :size="21" stroke-width="1.8" aria-hidden="true" />
      </LocalizedLink>
      <LocalizedLink
        to="/creators"
        class="mobile-bottom-nav__link"
        :aria-label="t('app.creatorsNav')"
        :class="{ 'is-active': ['creators', 'creator-profile'].includes(route.meta.routeName) }"
      >
        <UsersRound :size="21" stroke-width="1.8" aria-hidden="true" />
      </LocalizedLink>
      <LocalizedLink
        v-if="currentUser"
        :to="{ name: 'messages' }"
        class="mobile-bottom-nav__link"
        :aria-label="unreadMessageCount ? `${t('app.messages')} (${unreadMessageCount})` : t('app.messages')"
        :class="{ 'is-active': route.meta.routeName === 'messages' }"
      >
        <span class="mobile-bottom-nav__icon">
          <MessageCircle :size="21" stroke-width="1.8" aria-hidden="true" />
          <span v-if="unreadMessageCount" class="mobile-bottom-nav__badge" aria-hidden="true">
            {{ unreadMessageCount > 99 ? '99+' : unreadMessageCount }}
          </span>
        </span>
      </LocalizedLink>
      <LocalizedLink
        v-else
        to="/companies"
        class="mobile-bottom-nav__link"
        :aria-label="t('app.companies')"
        :class="{ 'is-active': ['companies', 'company-profile'].includes(route.meta.routeName) }"
      >
        <Building2 :size="21" stroke-width="1.8" aria-hidden="true" />
      </LocalizedLink>
      <LocalizedLink
        to="/campaigns"
        class="mobile-bottom-nav__link"
        :aria-label="t('app.campaigns')"
        :class="{ 'is-active': ['campaigns', 'campaign-detail'].includes(route.meta.routeName) }"
      >
        <Megaphone :size="21" stroke-width="1.8" aria-hidden="true" />
      </LocalizedLink>
      <LocalizedLink
        to="/account"
        class="mobile-bottom-nav__link"
        :aria-label="t('app.account')"
        :class="{
          'is-active': ['account', 'verify-email', 'reset-password'].includes(route.meta.routeName),
        }"
      >
        <UserRound :size="21" stroke-width="1.8" aria-hidden="true" />
      </LocalizedLink>
    </nav>
  </div>
</template>

<style lang="scss" src="./scss/App.scss"></style>
<style lang="scss" src="./scss/components/shared/SiteFooter.scss"></style>
