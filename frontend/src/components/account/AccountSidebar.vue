<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import {
  ExternalLink,
  LogOut,
  Building2,
  ClipboardCheck,
  House,
  LayoutDashboard,
  Mail,
  Megaphone,
  MessageCircle,
  UserRound,
  Users,
  Wrench,
} from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ref } from 'vue'
import { apiRequest } from '../../lib/api'
import { setCurrentUser } from '../../composables/useCurrentUser'
import { localizedPath } from '../../routePaths'
import WaveLogo from '../shared/WaveLogo.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import LanguageSwitcher from '../shared/LanguageSwitcher.vue'
import { mobileAccountSidebarOpen } from '../../composables/useMobileAccountSidebar'

const props = defineProps({
  user: {
    type: Object,
    default: null,
  },
  profile: { type: Object, default: null },
  pendingRegistrations: {
    type: Number,
    default: 0,
  },
  adminLayout: {
    type: Boolean,
    default: false,
  },
  mobileOnly: {
    type: Boolean,
    default: false,
  },
})

const route = useRoute()
const { t, locale } = useI18n()
const router = useRouter()
const signingOut = ref(false)
const signOutError = ref('')
const identity = computed(() => props.profile || props.user?.profile || {})
const name = computed(() => identity.value.displayName || identity.value.name || props.user?.name || props.user?.email || t('app.account'))
const image = computed(() => identity.value.avatarUrl || identity.value.logoUrl)
const publicRoute = computed(() => identity.value.slug ? {
  name: props.user?.accountType === 'creator' ? 'creator-profile' : 'company-profile',
  params: { slug: identity.value.slug },
} : null)
const primaryItems = computed(() => navigationItems.value.filter((item) => !item.route.startsWith('admin')))
const adminItems = computed(() => navigationItems.value.filter((item) => (
  item.route.startsWith('admin') && item.group !== 'marketing'
)))
const adminMarketingItems = computed(() => navigationItems.value.filter((item) => (
  item.group === 'marketing'
)))
const exploreItems = computed(() => [
  { route: 'creators', label: t('app.creatorsNav'), icon: Users },
])
async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  signOutError.value = ''
  try {
    await apiRequest('/auth/logout', { method: 'POST', body: {} })
    setCurrentUser(null)
    closeMobileSidebar()
    await router.replace({ path: localizedPath('account', locale.value), query: { mode: 'login' } })
  } catch (cause) {
    signOutError.value = cause.message
  } finally {
    signingOut.value = false
  }
}

function closeMobileSidebar() {
  mobileAccountSidebarOpen.value = false
}

function handleSidebarKeydown(event) {
  if (event.key === 'Escape') {
    closeMobileSidebar()
  }
}

watch(() => route.fullPath, closeMobileSidebar)

onMounted(() => {
  window.addEventListener('keydown', handleSidebarKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleSidebarKeydown)
  closeMobileSidebar()
})

const navigationItems = computed(() => {
  const canUseMarketplace = props.user?.approved || props.user?.isAdmin
  const creator = props.user?.accountType === 'creator'
  const items = []

  if (canUseMarketplace && creator) {
    items.push(
      { route: 'account-applications', labelKey: 'account.myApplications', icon: ClipboardCheck },
      { route: 'account-offers', labelKey: 'account.myOffers', icon: Mail },
      { route: 'account-inquiries', labelKey: 'account.directRequests', icon: MessageCircle },
      { route: 'account-bookmarks', labelKey: 'account.bookmarks', icon: House },
      { route: 'account-invitations', labelKey: 'account.campaignInvitations', icon: Mail },
    )
  } else if (canUseMarketplace) {
    items.push(
      { route: 'account-campaigns', labelKey: 'account.campaigns', icon: Megaphone },
      { route: 'account-inquiries', labelKey: 'account.directRequests', icon: MessageCircle },
    )
  }

  if (props.user?.isModerator) {
    items.push({ route: 'moderation', labelKey: 'moderation.title', icon: ClipboardCheck })
  }

  if (props.user?.isAdmin) {
    items.push(
      { route: 'admin', labelKey: 'adminDashboard.navOverview', icon: LayoutDashboard },
      { route: 'admin-registrations', labelKey: 'adminDashboard.navRegistrations', icon: ClipboardCheck, badge: props.pendingRegistrations },
      { route: 'admin-homepage', labelKey: 'adminDashboard.navHomepage', icon: House },
      { route: 'admin-creators', labelKey: 'adminDashboard.navCreators', icon: Users },
      { route: 'admin-companies', labelKey: 'adminDashboard.navCompanies', icon: Building2 },
      { route: 'admin-campaigns', labelKey: 'adminDashboard.navCampaigns', icon: Megaphone },
      { route: 'admin-email-templates', labelKey: 'adminDashboard.navEmailTemplates', icon: Mail },
      { route: 'admin-tools', labelKey: 'adminTools.title', icon: Wrench },
      {
        route: 'admin-subscribers',
        labelKey: 'adminDashboard.navSubscribers',
        icon: Mail,
        group: 'marketing',
      },
    )
  }

  return items.map((item) => ({
    ...item,
    label: t(item.labelKey),
  }))
})
</script>

<template>
  <button
    v-if="mobileAccountSidebarOpen"
    class="account-sidebar__backdrop"
    type="button"
    :aria-label="t('app.closeMenu')"
    @click="closeMobileSidebar"
  />
  <nav
    id="account-sidebar-menu"
    class="account-sidebar"
    :class="{
      'account-sidebar--admin': adminLayout,
      'account-sidebar--mobile-only': mobileOnly,
      'account-sidebar--mobile-open': mobileAccountSidebarOpen,
    }"
    :aria-label="t('account.navigation')"
  >
    <div v-if="user" class="account-sidebar__identity">
      <div class="account-sidebar__avatar"><img v-if="image" :src="image" :alt="name" /><WaveLogo v-else mark /></div>
      <div><strong>{{ name }}</strong><LocalizedLink v-if="publicRoute" :to="publicRoute" @click="closeMobileSidebar">{{ t('account.viewPublicProfile') }}<ExternalLink :size="12" aria-hidden="true" /></LocalizedLink></div>
    </div>
    <div class="account-sidebar__group account-sidebar__group--public">
      <div class="account-sidebar__language">
        <LanguageSwitcher expanded />
      </div>
      <LocalizedLink v-for="item in exploreItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span></LocalizedLink>
    </div>
    <div v-if="user" class="account-sidebar__group">
    <p class="account-sidebar__label">{{ t('account.navigation') }}</p>
    <LocalizedLink
      v-for="item in primaryItems"
      :key="item.route"
      class="account-sidebar__link"
      :class="{ 'is-active': route.meta.routeName === item.route }"
      :to="{ name: item.route }"
      :title="item.label"
      :aria-label="item.badge ? `${item.label} (${item.badge})` : item.label"
      :aria-current="route.meta.routeName === item.route ? 'page' : undefined"
      @click="closeMobileSidebar"
    >
      <component :is="item.icon" :size="18" aria-hidden="true" />
      <span class="account-sidebar__text">{{ item.label }}</span>
      <strong v-if="item.badge" class="account-sidebar__badge">{{ item.badge > 99 ? '99+' : item.badge }}</strong>
    </LocalizedLink>
    </div>
    <div v-if="adminItems.length" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('adminDashboard.navOverview') }}</p>
      <LocalizedLink v-for="item in adminItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" :aria-current="route.meta.routeName === item.route ? 'page' : undefined" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span><strong v-if="item.badge" class="account-sidebar__badge">{{ item.badge > 99 ? '99+' : item.badge }}</strong></LocalizedLink>
    </div>
    <div v-if="adminMarketingItems.length" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('adminDashboard.navMarketing') }}</p>
      <LocalizedLink v-for="item in adminMarketingItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" :aria-current="route.meta.routeName === item.route ? 'page' : undefined" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span><strong v-if="item.badge" class="account-sidebar__badge">{{ item.badge > 99 ? '99+' : item.badge }}</strong></LocalizedLink>
    </div>
    <div v-if="user" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('account.sidebarSettings') }}</p>
      <LocalizedLink class="account-sidebar__link" :to="{ name: 'account' }" @click="closeMobileSidebar"><UserRound :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('app.account') }}</span></LocalizedLink>
      <button class="account-sidebar__link account-sidebar__link--signout" type="button" :disabled="signingOut" @click="signOut"><LogOut :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('account.signOut') }}</span></button>
      <p v-if="signOutError" class="account-sidebar__error" role="alert">{{ signOutError }}</p>
    </div>
  </nav>
</template>

<style lang="scss" src="../../scss/components/account/AccountSidebar.scss"></style>
