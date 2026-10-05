<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import {
  Building2,
  ClipboardCheck,
  House,
  LayoutDashboard,
  Mail,
  Megaphone,
  MessageCircle,
  UserRound,
  Users,
} from '@lucide/vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import { mobileAccountSidebarOpen } from '../../composables/useMobileAccountSidebar'
import { unreadMessageCount } from '../../composables/useUnreadMessages'

const props = defineProps({
  user: {
    type: Object,
    default: null,
  },
  pendingRegistrations: {
    type: Number,
    default: 0,
  },
  adminLayout: {
    type: Boolean,
    default: false,
  },
})

const route = useRoute()
const { t } = useI18n()

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
  const items = [
    {
      route: 'account',
      labelKey: creator ? 'account.creatorProfile' : 'account.companyProfile',
      icon: creator ? UserRound : Building2,
    },
  ]

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

  if (canUseMarketplace) {
    items.push({ route: 'messages', labelKey: 'app.messages', icon: MessageCircle, badge: unreadMessageCount.value })
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
      'account-sidebar--mobile-open': mobileAccountSidebarOpen,
    }"
    :aria-label="t('account.navigation')"
  >
    <p class="account-sidebar__label">{{ t('account.navigation') }}</p>
    <LocalizedLink
      v-for="item in navigationItems"
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
  </nav>
</template>
