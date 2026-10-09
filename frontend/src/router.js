import { createRouter, createWebHistory } from 'vue-router'
import { localeNames, setLocale } from './i18n'
import { defaultLocale, localizedPath, localizedRouteName, routeSegments, localePrefixes } from './routePaths'

const accountView = () => import('./views/AccountView.vue')
const adminDashboardView = () => import('./views/AdminDashboardView.vue')
const legalView = () => import('./views/LegalView.vue')
const notFoundView = () => import('./views/NotFoundView.vue')

const routeViews = {
  support: () => import('./views/SupportView.vue'),
  'support-create': () => import('./views/SupportView.vue'),
  'support-track': () => import('./views/SupportView.vue'),
  'admin-support': () => import('./views/TicketInboxView.vue'),
  'account-support': () => import('./views/TicketInboxView.vue'),
  home: () => import('./views/HomeView.vue'),
  'country-creators': () => import('./views/CountryCreatorsView.vue'),
  'seo-guides': () => import('./views/SeoGuidesView.vue'),
  'seo-guide': () => import('./views/SeoGuidesView.vue'),
  'creator-guides': () => import('./views/SeoGuidesView.vue'),
  'creator-guide': () => import('./views/SeoGuidesView.vue'),
  'how-it-works': () => import('./views/HowItWorksView.vue'),
  'account-credits': () => import('./views/CreditsView.vue'),
  'admin-credit-settings': () => import('./views/AdminCreditSettingsView.vue'),
  account: accountView,
  'account-applications': accountView,
  'account-offers': accountView,
  'account-inquiries': accountView,
  'account-bookmarks': accountView,
  'account-invitations': accountView,
  'account-campaigns': accountView,
  'account-campaign-create': accountView,
  'account-campaign-detail': accountView,
  messages: () => import('./views/MessagesView.vue'),
  'verify-email': () => import('./views/VerifyEmailView.vue'),
  'reset-password': () => import('./views/ResetPasswordView.vue'),
  creators: () => import('./views/CreatorsView.vue'),
  'creator-profile': () => import('./views/CreatorProfileView.vue'),
  companies: () => import('./views/CompaniesView.vue'),
  'company-profile': () => import('./views/CompanyProfileView.vue'),
  campaigns: () => import('./views/CampaignsView.vue'),
  'campaign-detail': () => import('./views/CampaignDetailView.vue'),
  moderation: () => import('./views/ModerationView.vue'),
  admin: adminDashboardView,
  'admin-registrations': adminDashboardView,
  'admin-homepage': adminDashboardView,
  'admin-creators': adminDashboardView,
  'admin-companies': adminDashboardView,
  'admin-campaigns': adminDashboardView,
  'admin-email-templates': adminDashboardView,
  'admin-subscribers': () => import('./views/AdminSubscribersView.vue'),
  'admin-qr': () => import('./views/AdminQrView.vue'),
  'admin-tools': () => import('./views/AdminToolsView.vue'),
  imprint: legalView,
  'privacy-policy': legalView,
  'cookie-policy': legalView,
}

const adminSections = {
  'admin-support': 'support',
  'admin-credit-settings': 'credits',
  admin: 'overview',
  'admin-registrations': 'registrations',
  'admin-homepage': 'homepage',
  'admin-creators': 'creators',
  'admin-companies': 'companies',
  'admin-campaigns': 'campaigns',
  'admin-email-templates': 'email-templates',
  'admin-subscribers': 'subscribers',
  'admin-tools': 'tools',
  'admin-qr': 'qr',
}

const accountSections = {
  'account-support': 'support',
  'account-credits': 'credits',
  account: 'profile',
  'account-applications': 'applications',
  'account-offers': 'offers',
  'account-inquiries': 'inquiries',
  'account-bookmarks': 'bookmarks',
  'account-invitations': 'invitations',
  'account-campaigns': 'campaigns',
  'account-campaign-create': 'campaigns',
  'account-campaign-detail': 'campaigns',
}

const accountCampaignPages = {
  'account-campaign-create': 'create',
  'account-campaign-detail': 'detail',
}

function preferredLocale() {
  const storedLocale = window.localStorage.getItem('wave-locale')
  return Object.hasOwn(localeNames, storedLocale) ? storedLocale : defaultLocale
}

const localizedRoutes = Object.entries(routeSegments).flatMap(([locale, routes]) => (
  Object.entries(routes).map(([name]) => ({
    path: localizedPath(name, locale),
    name: localizedRouteName(name, locale),
    component: routeViews[name],
    meta: {
      locale,
      routeName: name,
      accountAccessPage: routeViews[name] === accountView,
      adminSection: adminSections[name],
      accountSection: accountSections[name],
      accountCampaignPage: accountCampaignPages[name],
    },
  }))
))

const legacyPaths = [
  ['/account', 'account'],
  ['/account/applications', 'account-applications'],
  ['/account/offers', 'account-offers'],
  ['/account/inquiries', 'account-inquiries'],
  ['/account/bookmarks', 'account-bookmarks'],
  ['/account/invitations', 'account-invitations'],
  ['/account/credits', 'account-credits'],
  ['/admin/credit-settings', 'admin-credit-settings'],
  ['/account/campaigns', 'account-campaigns'],
  ['/account/campaigns/new', 'account-campaign-create'],
  ['/account/campaigns/:slug', 'account-campaign-detail'],
  ['/messages', 'messages'],
  ['/verify-email', 'verify-email'],
  ['/reset-password', 'reset-password'],
  ['/creators', 'creators'],
  ['/creators/:slug', 'creator-profile'],
  ['/companies', 'companies'],
  ['/companies/:slug', 'company-profile'],
  ['/campaigns', 'campaigns'],
  ['/campaigns/:slug', 'campaign-detail'],
  ['/moderation', 'moderation'],
  ['/admin', 'admin'],
  ['/admin/registrations', 'admin-registrations'],
  ['/admin/homepage', 'admin-homepage'],
  ['/admin/creators', 'admin-creators'],
  ['/admin/companies', 'admin-companies'],
  ['/admin/campaigns', 'admin-campaigns'],
  ['/admin/email-templates', 'admin-email-templates'],
  ['/admin/subscribers', 'admin-subscribers'],
  ['/admin/tools', 'admin-tools'],
  ['/imprint', 'imprint'],
  ['/impressum', 'imprint'],
  ['/privacy-policy', 'privacy-policy'],
  ['/cookies', 'cookie-policy'],
]

const legacyRoutes = legacyPaths.map(([path, name]) => ({
  path,
  name: `legacy-${name}-${path.includes(':') ? 'detail' : 'index'}`,
  redirect: (to) => ({
    path: localizedPath(name, preferredLocale(), to.params),
    query: to.query,
    hash: to.hash,
  }),
}))

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }

    if (
      to.path === from.path
      && to.meta.accountSection === 'profile'
      && from.meta.accountSection === 'profile'
    ) {
      return false
    }

    return { top: 0 }
  },
  routes: [
    ...localizedRoutes,
    ...legacyRoutes,
    ...Object.entries(localePrefixes).filter(([locale, prefix]) => locale !== 'bs' && locale !== prefix).map(([locale, prefix]) => ({
      path: `/${locale}/:pathMatch(.*)*`,
      name: `legacy-prefix-${locale}`,
      redirect: to => ({ path: `/${prefix}/${Array.isArray(to.params.pathMatch) ? to.params.pathMatch.join('/') : to.params.pathMatch || ''}`, query: to.query, hash: to.hash }),
    })),
    ...Object.keys(localeNames).filter((locale) => locale !== defaultLocale).map((locale) => ({
      path: `/${localePrefixes[locale]}/:pathMatch(.*)*`,
      name: localizedRouteName('not-found', locale),
      component: notFoundView,
      meta: { locale, routeName: 'not-found' },
    })),
    { path: '/:pathMatch(.*)*', name: 'legacy-not-found', component: notFoundView },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.locale) {
    await setLocale(to.meta.locale)
  }
})

export default router
