<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import {
  ExternalLink,
  Flag,
  Coins,
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
  QrCode,
  Send,
  LifeBuoy,
  CornerUpRight,
  LogIn,
  UserRoundPlus,
  Handshake,
  Settings,
} from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ref } from 'vue'
import { logoutAccount } from '../../lib/pushSession'
import { useAdminNavigationCounts } from '../../composables/useAdminNavigationCounts'
import { setCurrentUser } from '../../composables/useCurrentUser'
import { localizedPath } from '../../routePaths'
import WaveLogo from '../shared/WaveLogo.vue'
import ProfileAvatar from '../shared/ProfileAvatar.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import LanguageSwitcher from '../shared/LanguageSwitcher.vue'
import { mobileAccountSidebarOpen } from '../../composables/useMobileAccountSidebar'
import { unreadMessageCount } from '../../composables/useUnreadMessages'
import { incompleteProfileScore, profileStrength } from '../../lib/profileStrength'

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
  // Desktop drawer opened from the header menu button outside account pages.
  drawer: {
    type: Boolean,
    default: false,
  },
})
const emit = defineEmits(['close'])

const adminCounts = useAdminNavigationCounts(() => props.user)
const route = useRoute()
const { t, locale } = useI18n()
const router = useRouter()
const signingOut = ref(false)
const signOutError = ref('')
const identity = computed(() => props.profile || props.user?.profile || {})
const name = computed(() => identity.value.displayName || identity.value.name || props.user?.name || props.user?.email || t('app.account'))
const image = computed(() => identity.value.avatarUrl || identity.value.logoUrl)
const strengthScore = computed(() => profileStrength(props.user ? { ...props.user, profile: identity.value } : null)?.score ?? null)
const publicRoute = computed(() => identity.value.slug ? {
  name: props.user?.accountType === 'creator' ? 'creator-profile' : 'company-profile',
  params: { slug: identity.value.slug },
} : null)
const profileHint = computed(() => {
  const score = incompleteProfileScore(props.user)
  return score === null ? '' : t('profileStrength.incomplete', { score })
})
const primaryItems = computed(() => navigationItems.value.filter((item) => !item.route.startsWith('admin') && !item.group))
const adminItems = computed(() => navigationItems.value.filter((item) => (
  item.route.startsWith('admin') && !item.group
)))
const adminMarketingItems = computed(() => navigationItems.value.filter((item) => (
  item.group === 'marketing'
)))
const adminSettingsItems = computed(() => navigationItems.value.filter((item) => item.group === 'admin-settings'))
const settingsItems = computed(() => navigationItems.value.filter((item) => item.group === 'settings'))
const exploreItems = computed(() => props.user?.profileComplete === false && !props.user?.isAdmin && !props.user?.isModerator ? [] : [
  props.user
    ? { route: 'companies', label: t('app.companies'), icon: Building2 }
    : { route: 'creators', label: t('app.creatorsNav'), icon: Users },
])
async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  signOutError.value = ''
  try {
    await logoutAccount()
    setCurrentUser(null)
    closeMobileSidebar()
    await router.replace({ path: localizedPath('login', locale.value) })
  } catch (cause) {
    signOutError.value = cause.message
  } finally {
    signingOut.value = false
  }
}

function closeMobileSidebar() {
  mobileAccountSidebarOpen.value = false
  if (props.drawer) emit('close')
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

// Five places for every account (overview, work, messages, profile, settings);
// reviews, blocks, tickets, credits and deletion live on the settings page.
const workRoutes = ['account-applications', 'account-inquiries', 'account-bookmarks', 'account-offers', 'account-invitations']
const campaignRoutes = ['account-campaigns', 'account-campaign-create', 'account-campaign-detail', 'account-inquiries']
const settingsRoutes = ['account-settings', 'account-reviews', 'account-blocks', 'account-support', 'account-credits']

const navigationItems = computed(() => {
  const canUseMarketplace = props.user?.isAdmin || props.user?.approved && props.user?.profileComplete !== false && props.user?.emailVerified !== false
  const creator = props.user?.accountType === 'creator'
  const provider = !creator && (props.user?.canOfferServices || props.user?.hasServiceHistory)
  const items = props.user ? [{ route: 'account-dashboard', labelKey: 'personalDashboard.title', icon: LayoutDashboard }] : []

  if (canUseMarketplace && creator) {
    items.push({ route: 'account-applications', labelKey: 'accountHub.work', icon: Handshake, match: workRoutes })
  } else if (canUseMarketplace) {
    items.push({ route: 'account-campaigns', labelKey: 'accountHub.campaigns', icon: Megaphone, match: campaignRoutes })
    if (provider) items.push({ route: 'account-applications', labelKey: 'agency.providerWork', icon: Handshake, match: workRoutes.filter((name) => name !== 'account-inquiries') })
  }
  if (canUseMarketplace && props.user) items.push({ route: 'messages', labelKey: 'app.messages', icon: MessageCircle, badge: unreadMessageCount.value || null })
  if (props.user) items.push({ route: 'account', labelKey: 'accountHub.profile', icon: UserRound, hint: profileHint.value })

  if (props.user?.isModerator) {
    items.push({ route: 'moderation', labelKey: 'moderation.title', icon: ClipboardCheck })
  }

  if (props.user?.isAdmin) {
    items.push(
      { route: 'admin', labelKey: 'adminDashboard.overviewPageTitle', icon: LayoutDashboard },
      { route: 'admin-listing-ads', labelKey: 'listingAds.title', icon: LayoutDashboard, group: 'marketing' },
      { route: 'admin-homepage', labelKey: 'adminDashboard.navHomepage', icon: House, group: 'marketing' },
      { route: 'admin-creators', labelKey: 'adminDashboard.navCreators', icon: Users },
      { route: 'admin-companies', labelKey: 'adminDashboard.navCompanies', icon: Building2 },
      { route: 'admin-campaigns', labelKey: 'adminDashboard.navCampaigns', icon: Megaphone },
      { route: 'admin-email-templates', labelKey: 'adminDashboard.navEmailTemplates', icon: Mail, group: 'marketing' },
      { route: 'admin-credit-settings', labelKey: 'credits.adminTitle', icon: Coins, group: 'admin-settings' },
      { route: 'admin-tools', labelKey: 'adminTools.title', icon: Wrench, group: 'admin-settings' },
      { route: 'admin-registrations', labelKey: 'adminDashboard.navRegistrations', icon: ClipboardCheck, group: 'admin-settings', badge: adminCounts.value?.approvals ?? props.pendingRegistrations },
      { route: 'admin-reports', labelKey: 'safety.reports', icon: Flag, group: 'admin-settings', badge: adminCounts.value?.reports },
      { route: 'admin-support', labelKey: 'support.adminTitle', icon: LifeBuoy, group: 'admin-settings', badge: adminCounts.value?.tickets },
      { route: 'admin-invitations', labelKey: 'adminInvitations.nav', icon: Send, group: 'marketing' },
      { route: 'admin-qr', labelKey: 'adminQr.title', icon: QrCode, group: 'marketing' },
      { route: 'admin-redirects', labelKey: 'adminRedirects.title', icon: CornerUpRight, group: 'marketing' },
      {
        route: 'admin-subscribers',
        labelKey: 'adminDashboard.navSubscribers',
        icon: Mail,
        group: 'marketing',
      },
    )
  }
  if (props.user) items.push({ route: 'account-settings', labelKey: 'account.sidebarSettings', icon: Settings, group: 'settings', match: settingsRoutes })

  return items.map((item) => ({
    ...item,
    label: t(item.labelKey),
  }))
})

function isActive(item) {
  return item.match ? item.match.includes(route.meta.routeName) : route.meta.routeName === item.route
}
</script>

<template>
  <button
    v-if="mobileAccountSidebarOpen || drawer"
    class="account-sidebar__backdrop"
    :class="{ 'account-sidebar__backdrop--drawer': drawer }"
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
      'account-sidebar--drawer': drawer,
    }"
    :aria-label="t('account.navigation')"
  >
    <div v-if="user" class="account-sidebar__identity">
      <LocalizedLink class="account-sidebar__overview" :to="{ name: 'account-dashboard' }" :aria-label="`${name} · ${t('personalDashboard.title')}`" @click="closeMobileSidebar"><ProfileAvatar class="account-sidebar__avatar" :src="image" :score="strengthScore"><WaveLogo mark /></ProfileAvatar></LocalizedLink>
      <div><LocalizedLink class="account-sidebar__name" :to="{ name: 'account-dashboard' }" @click="closeMobileSidebar"><strong>{{ name }}</strong></LocalizedLink><LocalizedLink v-if="publicRoute && user.profileComplete !== false" :to="publicRoute" @click="closeMobileSidebar">{{ t('account.viewPublicProfile') }}<ExternalLink :size="12" aria-hidden="true" /></LocalizedLink></div>
    </div>
    <div class="account-sidebar__group account-sidebar__group--public">
      <div v-if="!user" class="account-sidebar__language">
        <LanguageSwitcher expanded />
      </div>
      <template v-if="!user">
        <LocalizedLink class="account-sidebar__link" :to="{ name: 'login' }" @click="closeMobileSidebar"><LogIn :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('auth.signIn') }}</span></LocalizedLink>
        <LocalizedLink class="account-sidebar__link" :to="{ name: 'register' }" @click="closeMobileSidebar"><UserRoundPlus :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('auth.register') }}</span></LocalizedLink>
      </template>
      <LocalizedLink v-for="item in exploreItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span></LocalizedLink>
      <LocalizedLink v-if="!user" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === 'support' }" :to="{ name: 'support' }" @click="closeMobileSidebar"><LifeBuoy :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('support.label') }}</span></LocalizedLink>
    </div>
    <div v-if="user && primaryItems.length" class="account-sidebar__group">
      <p class="sr-only">{{ t('account.navigation') }}</p>
      <LocalizedLink
      v-for="item in primaryItems"
      :key="item.route"
      class="account-sidebar__link"
      :class="{ 'is-active': isActive(item) }"
      :to="{ name: item.route }"
      :title="item.label"
      :aria-label="item.badge != null ? `${item.label} (${item.badge})` : item.hint ? `${item.label} (${item.hint})` : item.label"
      :aria-current="isActive(item) ? 'page' : undefined"
      @click="closeMobileSidebar"
    >
      <component :is="item.icon" :size="18" aria-hidden="true" />
      <span class="account-sidebar__text">{{ item.label }}</span>
      <strong v-if="item.badge != null" class="account-sidebar__badge">{{ item.badge }}</strong>
      <span v-else-if="item.hint" class="account-sidebar__dot" :title="item.hint" />
    </LocalizedLink>
    </div>
    <div v-if="adminItems.length" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('adminDashboard.navOverview') }}</p>
      <LocalizedLink v-for="item in adminItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" :aria-current="route.meta.routeName === item.route ? 'page' : undefined" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span><strong v-if="item.badge != null" class="account-sidebar__badge">{{ item.badge }}</strong></LocalizedLink>
    </div>
    <div v-if="adminMarketingItems.length" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('adminDashboard.navMarketing') }}</p>
      <LocalizedLink v-for="item in adminMarketingItems" :key="item.route" class="account-sidebar__link" :class="{ 'is-active': route.meta.routeName === item.route }" :to="{ name: item.route }" :aria-current="route.meta.routeName === item.route ? 'page' : undefined" @click="closeMobileSidebar"><component :is="item.icon" :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ item.label }}</span><strong v-if="item.badge != null" class="account-sidebar__badge">{{ item.badge }}</strong></LocalizedLink>
    </div>
    <div v-if="adminSettingsItems.length" class="account-sidebar__group">
      <p class="account-sidebar__label">{{ t('account.sidebarSettings') }}</p>
      <LocalizedLink
      v-for="item in adminSettingsItems"
      :key="item.route"
      class="account-sidebar__link"
      :class="{ 'is-active': isActive(item) }"
      :to="{ name: item.route }"
      :title="item.label"
      :aria-label="item.badge != null ? `${item.label} (${item.badge})` : item.label"
      :aria-current="isActive(item) ? 'page' : undefined"
      @click="closeMobileSidebar"
    >
      <component :is="item.icon" :size="18" aria-hidden="true" />
      <span class="account-sidebar__text">{{ item.label }}</span>
      <strong v-if="item.badge != null" class="account-sidebar__badge">{{ item.badge }}</strong>
    </LocalizedLink>
    </div>
    <div v-if="user" class="account-sidebar__group account-sidebar__group--footer">
      <LocalizedLink
      v-for="item in settingsItems"
      :key="item.route"
      class="account-sidebar__link"
      :class="{ 'is-active': isActive(item) }"
      :to="{ name: item.route }"
      :title="item.label"
      :aria-label="item.badge != null ? `${item.label} (${item.badge})` : item.label"
      :aria-current="isActive(item) ? 'page' : undefined"
      @click="closeMobileSidebar"
    >
      <component :is="item.icon" :size="18" aria-hidden="true" />
      <span class="account-sidebar__text">{{ item.label }}</span>
      <strong v-if="item.badge != null" class="account-sidebar__badge">{{ item.badge }}</strong>
    </LocalizedLink>
      <button class="account-sidebar__link account-sidebar__link--signout" type="button" :disabled="signingOut" @click="signOut"><LogOut :size="18" aria-hidden="true" /><span class="account-sidebar__text">{{ t('account.signOut') }}</span></button>
      <p v-if="signOutError" class="account-sidebar__error" role="alert">{{ signOutError }}</p>
    </div>
  </nav>
</template>

<style lang="scss" src="../../scss/components/account/AccountSidebar.scss"></style>
