<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bell, Building2, Download, House, LogOut, Menu, Megaphone, MessageCircle, UserRound, UsersRound, X } from '@lucide/vue'
import { registerSW } from 'virtual:pwa-register'
import LanguageSwitcher from './components/shared/LanguageSwitcher.vue'
import HeaderCreatorSearch from './components/shared/HeaderCreatorSearch.vue'
import LocalizedLink from './components/shared/LocalizedLink.vue'
import WaveWordmark from './components/shared/WaveWordmark.vue'
import CookieConsentBanner from './components/shared/CookieConsentBanner.vue'
import { currentUser, loadCurrentUser, setCurrentUser } from './composables/useCurrentUser'
import { unreadMessageCount } from './composables/useUnreadMessages'
import { mobileAccountSidebarOpen } from './composables/useMobileAccountSidebar'
import { setLocale } from './i18n'
import { apiGet, apiRequest, formatDate } from './lib/api'
import { startInboxSync } from './lib/inboxSync'
import { updateSeo } from './lib/seo'
import { trackPageView } from './lib/privacyMetrics'
import { localizedPath } from './routePaths'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const currentYear = new Date().getFullYear()
const mobileMenuOpen = ref(false)
const cookieConsentBanner = ref(null)
const mobileChromeHidden = ref(false)
const mobileMenu = ref(null)
const authMenuOpen = ref(false)
const authMenu = ref(null)
const authMenuTrigger = ref(null)
const authMenuError = ref('')
const signingOut = ref(false)
const notificationsMenuOpen = ref(false)
const notificationsMenu = ref(null)
const notifications = ref([])
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
const unreadNotificationCount = computed(() => (
  notifications.value.filter((item) => !item.readAt).length
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
const mobileNavigationOpen = computed(() => (
  isMobileAccountNavigationPage.value ? mobileAccountSidebarOpen.value : mobileMenuOpen.value
))
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
watch(() => route.fullPath, () => {
  mobileMenuOpen.value = false
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
  mobileMenuOpen.value = false
  if (user?.id !== previousUser?.id) {
    closeRealtime()
    realtimeLastEventId = ''
    seenRealtimeNotificationIds.clear()
    notifications.value = []
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
    const noindex = ['account', 'messages', 'verify-email', 'reset-password', 'moderation', 'admin'].includes(routeName)
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
  loadCurrentUser().catch((cause) => console.error('Unable to load the current Wave account.', cause))
  window.addEventListener('beforeinstallprompt', captureInstallPrompt)
  window.addEventListener('appinstalled', onAppInstalled)
  window.addEventListener('wave:conversations-updated', handleConversationsUpdated)
  document.addEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeNotificationsOnOutsideClick)
})
onBeforeUnmount(() => {
  inboxSync?.stop()
  closeRealtime()
  window.removeEventListener('scroll', handleMobileScroll)
  window.removeEventListener('resize', resetMobileChrome)
  if (scrollFrame !== null) window.cancelAnimationFrame(scrollFrame)
  window.removeEventListener('beforeinstallprompt', captureInstallPrompt)
  window.removeEventListener('appinstalled', onAppInstalled)
  window.removeEventListener('wave:conversations-updated', handleConversationsUpdated)
  document.removeEventListener('visibilitychange', checkForWaveUpdate)
  if (serviceWorkerUpdateTimer !== null) window.clearInterval(serviceWorkerUpdateTimer)
  document.removeEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeNotificationsOnOutsideClick)
})

async function loadNotifications() {
  const userId = currentUser.value?.id
  if (!userId) return
  const requestVersion = ++notificationsRequestVersion
  try {
    const response = await apiGet('/me/notifications')
    if (
      currentUser.value?.id !== userId
      || requestVersion !== notificationsRequestVersion
    ) {
      return
    }
    notifications.value = response.data
    const loadedIds = new Set(response.data.map((notification) => notification.id))
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => !loadedIds.has(id),
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

async function loadUnreadMessages() {
  const userId = currentUser.value?.id
  if (!userId) {
    unreadMessageCount.value = 0
    return
  }
  const requestVersion = ++unreadMessagesRequestVersion

  try {
    const response = await apiGet('/me/conversations')
    if (
      currentUser.value?.id !== userId
      || requestVersion !== unreadMessagesRequestVersion
    ) {
      return
    }
    unreadMessageCount.value = response.data.reduce(
      (total, conversation) => total + conversation.unreadCount,
      0,
    )
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
  if (isMobileAccountNavigationPage.value) {
    mobileAccountSidebarOpen.value = !mobileAccountSidebarOpen.value
    return
  }

  mobileMenuOpen.value = !mobileMenuOpen.value
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
        if (hasSeenRealtimeNotification(update.notificationId)) return

        if (update.notificationType !== 'chat_message') {
          addOptimisticUnreadNotification(update.notificationId)
          void loadNotifications()
        }
        const isOpenConversation = (
          update.notificationType === 'chat_message'
          && route.meta.routeName === 'messages'
          && Number(route.query.conversation) === Number(update.conversationId)
          && document.visibilityState === 'visible'
        )
        if (update.notificationType === 'chat_message') {
          const senderId = update.message?.senderId
          if (senderId === undefined || senderId !== currentUser.value?.id) {
            if (!isOpenConversation) {
              unreadMessageCount.value += 1
            }
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

function captureInstallPrompt(event) {
  event.preventDefault()
  installPrompt = event
  installAvailable.value = true
}

function onAppInstalled() {
  installPrompt = null
  installAvailable.value = false
  installHelpVisible.value = false
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
  try {
    await apiRequest('/me/notifications/read-all', { method: 'POST', body: {} })
    notifications.value = notifications.value.map((notification) => (
      notification.readAt ? notification : { ...notification, readAt: new Date().toISOString() }
    ))
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => !pendingIds.has(id),
    )
  } catch (cause) {
    notificationsError.value = cause.message
  } finally {
    markingAllRead.value = false
  }
}

async function openNotification(notification) {
  notificationsError.value = ''
  try {
    const response = await apiRequest(`/me/notifications/${notification.id}/read`, {
      method: 'POST',
      body: {},
    })
    const index = notifications.value.findIndex((item) => item.id === notification.id)
    if (index !== -1) notifications.value.splice(index, 1, response.data)
    optimisticUnreadNotificationIds.value = optimisticUnreadNotificationIds.value.filter(
      (id) => id !== notification.id,
    )
    notificationsMenuOpen.value = false
    if (notification.conversationId) {
      await router.push({
        path: localizedPath('messages', locale.value),
        query: { conversation: notification.conversationId },
      })
      return
    }
    const destination = notification.type === 'offer_received'
      ? 'account-offers'
      : ['campaign_invitation', 'invitation_declined'].includes(notification.type)
        ? 'account-invitations'
        : ['creator_inquiry_received', 'creator_inquiry_accepted'].includes(notification.type)
          ? 'account-inquiries'
          : ['application_received', 'application_rejected'].includes(notification.type)
            ? 'account-applications'
            : 'account'
    await router.push({ path: localizedPath(destination, locale.value) })
  } catch (cause) {
    notificationsError.value = cause.message
  }
}

function toggleNotifications() {
  notificationsMenuOpen.value = !notificationsMenuOpen.value
  if (notificationsMenuOpen.value) void loadNotifications()
}

function closeMobileMenuOnOutsideClick(event) {
  if (mobileMenu.value && event.target instanceof Node && !mobileMenu.value.contains(event.target)) {
    mobileMenuOpen.value = false
  }
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

async function signOut() {
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
            @keydown.esc.stop.prevent="notificationsMenuOpen = false"
          >
            <button
              class="header-notifications__trigger"
              type="button"
              :aria-label="t('app.notifications')"
              aria-haspopup="true"
              aria-controls="header-notifications-panel"
              :aria-expanded="notificationsMenuOpen"
              @click="toggleNotifications"
            >
              <Bell :size="19" stroke-width="1.8" aria-hidden="true" />
              <span v-if="unreadNotificationCount" class="header-notifications__badge" aria-hidden="true">
                {{ unreadNotificationCount > 99 ? '99+' : unreadNotificationCount }}
              </span>
            </button>
            <div
              v-if="notificationsMenuOpen"
              id="header-notifications-panel"
              class="header-notifications__panel"
            >
              <div class="header-notifications__heading">
                <h2>{{ t('app.notifications') }}</h2>
                <button
                  v-if="unreadNotificationCount"
                  type="button"
                  :disabled="markingAllRead"
                  @click="markAllNotificationsRead"
                >{{ t('app.markAllRead') }}</button>
              </div>
              <p v-if="notificationsError" class="header-notifications__error" role="alert">
                {{ notificationsError }}
              </p>
              <div v-if="currentUser" class="header-notifications__push">
                <button
                  v-if="pushSupported && pushConfig.enabled"
                  type="button"
                  :disabled="pushBusy"
                  @click="togglePushNotifications"
                >{{ t(pushSubscribed ? 'app.pushDisable' : 'app.pushEnable') }}</button>
                <p v-if="pushStatus" role="status">{{ t(`app.${pushStatus}`) }}</p>
              </div>
              <p v-if="!notificationsError && !notifications.length">{{ t('app.noNotifications') }}</p>
              <div v-else-if="!notificationsError" class="header-notifications__list">
                <button
                  v-for="notification in notifications"
                  :key="notification.id"
                  class="header-notifications__item"
                  :class="{ 'is-unread': !notification.readAt }"
                  type="button"
                  @click="openNotification(notification)"
                >
                  <span>{{ t(`campaignChat.notificationTypes.${notification.type}`, {
                    actor: notification.actorName,
                    campaign: notification.campaign?.title || '',
                  }) }}</span>
                  <time :datetime="notification.createdAt">{{ formatDate(notification.createdAt) }}</time>
                </button>
              </div>
            </div>
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
              type="button"
              :aria-label="t('app.account')"
              aria-haspopup="true"
              aria-controls="header-user-menu-panel"
              :aria-expanded="authMenuOpen"
              @click="authMenuOpen = !authMenuOpen"
            >
              <UserRound :size="19" stroke-width="1.8" aria-hidden="true" />
            </button>
            <div
              v-if="authMenuOpen"
              id="header-user-menu-panel"
              class="header-user-menu__panel"
            >
              <template v-if="currentUser">
                <LocalizedLink
                  class="header-user-menu__item"
                  :to="{ name: 'account' }"
                >
                  <UserRound :size="16" stroke-width="1.8" aria-hidden="true" />
                  {{ t('app.account') }}
                </LocalizedLink>
                <button
                  class="header-user-menu__item"
                  type="button"
                  :disabled="signingOut"
                  @click="signOut"
                >
                  <LogOut :size="16" stroke-width="1.8" aria-hidden="true" />
                  {{ t('account.signOut') }}
                </button>
                <p v-if="authMenuError" class="header-user-menu__error" role="alert">
                  {{ authMenuError }}
                </p>
              </template>
              <template v-else>
                <LocalizedLink
                  class="header-user-menu__item"
                  :to="{ name: 'account', query: { mode: 'login' } }"
                >
                  {{ t('auth.signIn') }}
                </LocalizedLink>
                <LocalizedLink
                  class="header-user-menu__item"
                  :to="{ name: 'account', query: { mode: 'register' } }"
                >
                  {{ t('auth.register') }}
                </LocalizedLink>
              </template>
            </div>
          </div>
          <div class="desktop-language-switcher">
            <LanguageSwitcher />
          </div>
        </div>
        <div ref="mobileMenu" class="mobile-menu">
          <button
            class="mobile-menu__toggle"
            type="button"
            :aria-label="mobileNavigationOpen ? t('app.closeMenu') : t('app.openMenu')"
            :aria-expanded="mobileNavigationOpen"
            :aria-controls="isMobileAccountNavigationPage ? 'account-sidebar-menu' : 'mobile-navigation-menu'"
            @click="toggleMobileNavigation"
          >
            <X v-if="mobileNavigationOpen" :size="22" stroke-width="1.8" aria-hidden="true" />
            <Menu v-else :size="22" stroke-width="1.8" aria-hidden="true" />
          </button>
          <div
            v-if="mobileMenuOpen"
            id="mobile-navigation-menu"
            class="mobile-menu__panel"
            @keydown.esc.stop.prevent="mobileMenuOpen = false"
          >
            <nav class="mobile-menu__links" :aria-label="t('app.mainNavigation')">
              <LocalizedLink
                to="/creators"
                :class="{ 'is-active': ['creators', 'creator-profile'].includes(route.meta.routeName) }"
              >
                <UsersRound :size="17" aria-hidden="true" />
                {{ t('app.creators') }}
              </LocalizedLink>
              <LocalizedLink
                to="/companies"
                :class="{ 'is-active': ['companies', 'company-profile'].includes(route.meta.routeName) }"
              >
                <Building2 :size="17" aria-hidden="true" />
                {{ t('app.companies') }}
              </LocalizedLink>
              <LocalizedLink
                to="/campaigns"
                :class="{ 'is-active': ['campaigns', 'campaign-detail'].includes(route.meta.routeName) }"
              >
                <Megaphone :size="17" aria-hidden="true" />
                {{ t('app.campaigns') }}
              </LocalizedLink>
            </nav>
            <div class="mobile-menu__language">
              <span>{{ t('app.language') }}</span>
              <LanguageSwitcher />
            </div>
          </div>
        </div>
      </div>
    </header>

    <main>
      <RouterView />
    </main>

    <footer
      v-if="!hideSiteFooter"
      class="site-footer"
      :class="{ 'site-footer--admin': route.meta.adminSection }"
    >
      <div class="site-footer__inner">
        <div class="site-footer__brand">
          <LocalizedLink to="/" class="site-footer__wordmark">
            <WaveWordmark :light="true" :aria-label="t('app.homeAria')" />
          </LocalizedLink>
          <p>{{ t('app.footerTagline') }}</p>
          <div
            class="site-footer__social"
            role="group"
            :aria-label="t('app.socialLinks')"
            aria-describedby="wave-social-note"
          >
            <span class="site-footer__social-icon site-footer__social-icon--instagram" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <rect x="3.5" y="3.5" width="17" height="17" rx="5" />
                <circle cx="12" cy="12" r="4" />
                <circle class="site-footer__social-dot" cx="17.7" cy="6.7" r="1" />
              </svg>
            </span>
            <span class="site-footer__social-icon site-footer__social-icon--tiktok" aria-hidden="true">
              ♪
            </span>
            <span class="site-footer__social-icon site-footer__social-icon--facebook" aria-hidden="true">
              f
            </span>
            <span class="site-footer__social-icon site-footer__social-icon--x" aria-hidden="true">
              X
            </span>
          </div>
          <span id="wave-social-note" class="site-footer__social-note">
            {{ t('app.socialComingSoon') }}
          </span>
        </div>

        <nav class="site-footer__column" :aria-label="t('app.footerExplore')">
          <h2>{{ t('app.footerExplore') }}</h2>
          <LocalizedLink to="/creators">{{ t('app.creatorsNav') }}</LocalizedLink>
          <LocalizedLink to="/companies">{{ t('app.companies') }}</LocalizedLink>
          <LocalizedLink to="/campaigns">{{ t('app.campaigns') }}</LocalizedLink>
        </nav>

        <nav class="site-footer__column" :aria-label="t('app.footerLegal')">
          <h2>{{ t('app.footerLegal') }}</h2>
          <LocalizedLink :to="{ name: 'imprint' }">{{ t('legal.imprint.title') }}</LocalizedLink>
          <LocalizedLink :to="{ name: 'privacy-policy' }">{{ t('legal.privacy.title') }}</LocalizedLink>
          <LocalizedLink :to="{ name: 'cookie-policy' }">{{ t('legal.cookies.title') }}</LocalizedLink>
          <button type="button" @click="cookieConsentBanner?.open()">
            {{ t('legal.openCookieSettings') }}
          </button>
        </nav>

        <div class="site-footer__column site-footer__contact">
          <h2>{{ t('app.footerContact') }}</h2>
          <p>{{ t('app.footerContactText') }}</p>
          <a class="site-footer__email" href="mailto:info@wave.ba">
            info@wave.ba <span aria-hidden="true">↗</span>
          </a>
          <span class="site-footer__operator">{{ t('app.footerOperator') }}</span>
        </div>
      </div>
      <div class="site-footer__bottom">
        <span>{{ t('app.copyright', { year: currentYear }) }}</span>
        <span>{{ t('app.footerMvp') }}</span>
      </div>
    </footer>

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
