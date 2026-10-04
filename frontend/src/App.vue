<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bell, Building2, House, LogOut, Menu, Megaphone, MessageCircle, UserRound, UsersRound, X } from '@lucide/vue'
import LanguageSwitcher from './components/shared/LanguageSwitcher.vue'
import HeaderCreatorSearch from './components/shared/HeaderCreatorSearch.vue'
import LocalizedLink from './components/shared/LocalizedLink.vue'
import WaveWordmark from './components/shared/WaveWordmark.vue'
import { currentUser, loadCurrentUser, setCurrentUser } from './composables/useCurrentUser'
import { setLocale } from './i18n'
import { apiGet, apiRequest, formatDate } from './lib/api'
import { updateSeo } from './lib/seo'
import { localizedPath } from './routePaths'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const mobileMenuOpen = ref(false)
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
const unreadNotificationCount = computed(() => notifications.value.filter((item) => !item.readAt).length)
let notificationsTimer = null
watch(locale, setLocale)
watch(() => route.fullPath, () => {
  mobileMenuOpen.value = false
  authMenuOpen.value = false
  notificationsMenuOpen.value = false
  if (route.meta.routeName === 'messages' && currentUser.value) void loadNotifications()
})
watch(currentUser, (user) => {
  if (!user) {
    notificationsMenuOpen.value = false
    notifications.value = []
    notificationsError.value = ''
    return
  }
  void loadNotifications()
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
  loadCurrentUser().catch((cause) => console.error('Unable to load the current Wave account.', cause))
  notificationsTimer = window.setInterval(() => {
    if (currentUser.value) void loadNotifications()
  }, 30000)
  document.addEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.addEventListener('pointerdown', closeNotificationsOnOutsideClick)
})
onBeforeUnmount(() => {
  if (notificationsTimer !== null) window.clearInterval(notificationsTimer)
  document.removeEventListener('pointerdown', closeMobileMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeAuthMenuOnOutsideClick)
  document.removeEventListener('pointerdown', closeNotificationsOnOutsideClick)
})

async function loadNotifications() {
  if (!currentUser.value) return
  try {
    const response = await apiGet('/me/notifications')
    notifications.value = response.data
    notificationsError.value = ''
  } catch (cause) {
    if (cause.status === 401) {
      setCurrentUser(null)
      return
    }
    notificationsError.value = cause.message
  }
}

async function markAllNotificationsRead() {
  markingAllRead.value = true
  notificationsError.value = ''
  try {
    await apiRequest('/me/notifications/read-all', { method: 'POST', body: {} })
    notifications.value = notifications.value.map((notification) => (
      notification.readAt ? notification : { ...notification, readAt: new Date().toISOString() }
    ))
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
    notificationsMenuOpen.value = false
    if (notification.conversationId) {
      await router.push({
        path: localizedPath('messages', locale.value),
        query: { conversation: notification.conversationId },
      })
      return
    }
    await router.push({
      path: localizedPath(
        notification.type === 'offer_received' ? 'account-offers' : 'account',
        locale.value,
      ),
    })
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
    await router.push({
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
      'site-shell--account': currentUser
        && ['creator', 'company'].includes(currentUser.accountType)
        && route.meta.accountSection,
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
              <p v-else-if="!notifications.length">{{ t('app.noNotifications') }}</p>
              <div v-else class="header-notifications__list">
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
            :aria-label="t('app.messages')"
            :title="t('app.messages')"
          >
            <MessageCircle :size="19" stroke-width="1.8" aria-hidden="true" />
          </LocalizedLink>
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
            :aria-label="mobileMenuOpen ? t('app.closeMenu') : t('app.openMenu')"
            :aria-expanded="mobileMenuOpen"
            aria-controls="mobile-navigation-menu"
            @click="mobileMenuOpen = !mobileMenuOpen"
          >
            <X v-if="mobileMenuOpen" :size="22" stroke-width="1.8" aria-hidden="true" />
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

    <footer class="site-footer" :class="{ 'site-footer--admin': route.meta.adminSection }">
      <div class="site-footer__top">
        <WaveWordmark :light="true" :aria-label="t('app.homeAria')" />
        <p>{{ t('app.footerTagline') }}</p>
        <a href="mailto:info@wave.ba">{{ t('app.sayHello') }} <span aria-hidden="true">↗</span></a>
      </div>
      <div class="site-footer__bottom">
        <span>{{ t('app.copyright') }}</span>
        <span>{{ t('app.footerMvp') }}</span>
      </div>
    </footer>

    <nav class="mobile-bottom-nav" :aria-label="t('app.mainNavigation')">
      <LocalizedLink
        to="/"
        class="mobile-bottom-nav__link"
        :class="{ 'is-active': route.meta.routeName === 'home' }"
      >
        <House :size="21" stroke-width="1.8" aria-hidden="true" />
        <span>{{ t('app.home') }}</span>
      </LocalizedLink>
      <LocalizedLink
        to="/creators"
        class="mobile-bottom-nav__link"
        :class="{ 'is-active': ['creators', 'creator-profile'].includes(route.meta.routeName) }"
      >
        <UsersRound :size="21" stroke-width="1.8" aria-hidden="true" />
        <span>{{ t('app.creators') }}</span>
      </LocalizedLink>
      <LocalizedLink
        to="/companies"
        class="mobile-bottom-nav__link"
        :class="{ 'is-active': ['companies', 'company-profile'].includes(route.meta.routeName) }"
      >
        <Building2 :size="21" stroke-width="1.8" aria-hidden="true" />
        <span>{{ t('app.companies') }}</span>
      </LocalizedLink>
      <LocalizedLink
        to="/campaigns"
        class="mobile-bottom-nav__link"
        :class="{ 'is-active': ['campaigns', 'campaign-detail'].includes(route.meta.routeName) }"
      >
        <Megaphone :size="21" stroke-width="1.8" aria-hidden="true" />
        <span>{{ t('app.campaigns') }}</span>
      </LocalizedLink>
      <LocalizedLink
        to="/account"
        class="mobile-bottom-nav__link"
        :class="{
          'is-active': ['account', 'verify-email', 'reset-password'].includes(route.meta.routeName),
        }"
      >
        <UserRound :size="21" stroke-width="1.8" aria-hidden="true" />
        <span>{{ t('app.account') }}</span>
      </LocalizedLink>
    </nav>
  </div>
</template>
