<script setup>
import { computed } from 'vue'
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

const navigationItems = computed(() => {
  const items = props.user?.accountType === 'creator'
    ? [
        { route: 'account', labelKey: 'account.creatorProfile', icon: UserRound },
        { route: 'account-applications', labelKey: 'account.myApplications', icon: ClipboardCheck },
        { route: 'account-offers', labelKey: 'account.myOffers', icon: Mail },
        { route: 'account-inquiries', labelKey: 'account.directRequests', icon: MessageCircle },
        { route: 'account-bookmarks', labelKey: 'account.bookmarks', icon: House },
        { route: 'account-invitations', labelKey: 'account.campaignInvitations', icon: Mail },
      ]
    : [
        { route: 'account', labelKey: 'account.companyProfile', icon: Building2 },
        { route: 'account-campaigns', labelKey: 'account.campaigns', icon: Megaphone },
        { route: 'account-applications', labelKey: 'account.companyApplications', icon: Users },
        { route: 'account-inquiries', labelKey: 'account.directRequests', icon: MessageCircle },
      ]

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
  <nav
    class="account-sidebar"
    :class="{ 'account-sidebar--admin': adminLayout }"
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
      :aria-label="item.label"
      :aria-current="route.meta.routeName === item.route ? 'page' : undefined"
    >
      <component :is="item.icon" :size="18" aria-hidden="true" />
      <span class="account-sidebar__text">{{ item.label }}</span>
      <strong v-if="item.badge" class="account-sidebar__badge">{{ item.badge }}</strong>
    </LocalizedLink>
  </nav>
</template>
