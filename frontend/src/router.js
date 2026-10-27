import { createRouter, createWebHistory } from 'vue-router'
import { localeNames, setLocale } from './i18n'
import { defaultLocale, localizedPath, localizedRouteName, routeSegments, localePrefixes, isSharedRoute, legacyLocalizedDetailPath } from './routePaths'
import { ensureCurrentUser } from './composables/useCurrentUser'
import { accountAccessRedirect, accountLanding } from './lib/accountAccess'
import { resolvePublicRedirect } from './lib/publicRedirects'
import { registrationReturn } from './lib/registrationIntent'

const accountView = () => import('./views/AccountView.vue')
const adminDashboardView = () => import('./views/AdminDashboardView.vue')
const legalView = () => import('./views/LegalView.vue')
const notFoundView = () => import('./views/NotFoundView.vue')

const routeViews = {
  'account-dashboard': () => import('./views/AccountDashboardView.vue'),
  'account-settings': () => import('./views/AccountSettingsView.vue'),
  'account-email-preferences': () => import('./views/AccountEmailPreferencesView.vue'),
  'account-profile-guide': () => import('./views/AccountProfileGuideView.vue'),
  'account-reviews': () => import('./views/AdminSafetyView.vue'),
  'admin-reports': () => import('./views/AdminSafetyView.vue'),
  'account-blocks': () => import('./views/AdminSafetyView.vue'),
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
  login: accountView,
  register: accountView,
  'forgot-password': accountView,
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
  search: () => import('./views/SearchView.vue'),
  creators: () => import('./views/CreatorsView.vue'),
  'creator-profile': () => import('./views/CreatorProfileView.vue'),
  companies: () => import('./views/CompaniesView.vue'),
  'company-profile': () => import('./views/CompanyProfileView.vue'),
  campaigns: () => import('./views/CampaignsView.vue'),
  'campaign-detail': () => import('./views/CampaignDetailView.vue'),
  moderation: () => import('./views/ModerationView.vue'),
  admin: adminDashboardView,
  'admin-registrations': adminDashboardView,
  'admin-listing-ads': () => import('./views/AdminListingAdsView.vue'),
  'admin-homepage': adminDashboardView,
  'admin-creators': adminDashboardView,
  'admin-companies': adminDashboardView,
  'admin-campaigns': adminDashboardView,
  'admin-email-templates': adminDashboardView,
  'admin-subscribers': () => import('./views/AdminSubscribersView.vue'),
  'admin-qr': () => import('./views/AdminQrView.vue'),
  'admin-invitations': () => import('./views/AdminInvitationsView.vue'),
  'admin-redirects': () => import('./views/AdminRedirectsView.vue'),
  'admin-tools': () => import('./views/AdminToolsView.vue'),
  imprint: legalView,
  'privacy-policy': legalView,
  'cookie-policy': legalView,
  'terms-of-use': legalView,
}

const adminSections = {
  'admin-reports': 'reports',
  'admin-support': 'support',
  'admin-credit-settings': 'credits',
  admin: 'overview',
  'admin-registrations': 'registrations',
  'admin-listing-ads': 'listing-ads',
  'admin-homepage': 'homepage',
  'admin-creators': 'creators',
  'admin-companies': 'companies',
  'admin-campaigns': 'campaigns',
  'admin-email-templates': 'email-templates',
  'admin-subscribers': 'subscribers',
  'admin-tools': 'tools',
  'admin-qr': 'qr',
  'admin-invitations': 'invitations',
  'admin-redirects': 'redirects',
}

const accountSections = {
  'account-dashboard': 'dashboard',
  'account-settings': 'settings',
  'account-reviews': 'reviews',
  'account-blocks': 'blocks',
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
  Object.entries(routes).filter(([name]) => !isSharedRoute(name)).map(([name]) => ({
    path: localizedPath(name, locale),
    name: localizedRouteName(name, locale),
    component: routeViews[name],
    ...(['account-offers', 'account-invitations'].includes(name) ? { redirect: to => ({ name: localizedRouteName('account-applications', locale), query: to.query, hash: to.hash }) } : {}),
    meta: {
      locale,
      routeName: name,
      accountAccessPage: routeViews[name] === accountView,
      authMode: { login: 'login', register: 'register', 'forgot-password': 'reset' }[name],
      adminSection: adminSections[name],
      accountSection: accountSections[name],
      safetySection: { 'account-reviews': 'reviews', 'admin-reports': 'reports', 'account-blocks': 'blocks' }[name],
      accountCampaignPage: accountCampaignPages[name],
    },
  }))
))

const legacyPaths = [
  ['/account', 'account'],
  ['/login', 'login'],
  ['/register', 'register'],
  ['/forgot-password', 'forgot-password'],
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
  ['/admin/redirects', 'admin-redirects'],
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
    ...Object.keys(routeSegments.bs).filter(isSharedRoute).map(name => ({ path: localizedPath(name, 'bs'), name, component: routeViews[name], meta: { routeName: name, sharedPublic: true } })),
    ...Object.entries(routeSegments).flatMap(([locale, routes]) => Object.keys(routes).filter(isSharedRoute).filter(name => legacyLocalizedDetailPath(name, locale) !== localizedPath(name, locale)).map(name => ({ path: legacyLocalizedDetailPath(name, locale), redirect: to => ({ name, params: to.params, query: to.query, hash: to.hash }) }))),
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

router.beforeEach(async (to, from) => {
  if (to.meta.sharedPublic) await setLocale(preferredLocale())
  if (to.meta.locale) {
    await setLocale(to.meta.locale)
  }
  const navigationLocale = to.meta.locale || (to.meta.sharedPublic ? preferredLocale() : defaultLocale)
  const legacyAuth = ['login', 'register', 'reset'].includes(to.query.mode) ? (to.query.mode === 'reset' ? 'forgot-password' : to.query.mode) : null
  if ((to.meta.routeName === 'account' || to.meta.authMode) && legacyAuth) {
    const query = { ...to.query }
    delete query.mode
    return { name: localizedRouteName(legacyAuth, navigationLocale), query, hash: to.hash, replace: true }
  }
  let user
  try { user = await ensureCurrentUser() } catch { /* The page API retains its access/error checks. */ }
  if (['account', 'account-dashboard', 'login', 'register'].includes(to.meta.routeName) && !to.query.bookmark) {
    const pendingReturn = registrationReturn(user)
    if (pendingReturn) return pendingReturn
  }
  if (user && to.meta.authMode && !to.query.bookmark && !to.query.returnTo) return { name: localizedRouteName(accountLanding(user), navigationLocale) }
  const gate = accountAccessRedirect(to, user)
  if (gate) return { name: localizedRouteName(gate.name, navigationLocale) }
  if (user && to.meta.routeName === 'account' && !to.query.bookmark && !to.query.returnTo && (['login', 'register'].includes(to.query.mode) || to.query.oauth === 'success') && accountLanding(user) !== 'account') {
    return { name: localizedRouteName(accountLanding(user), navigationLocale) }
  }
  // Symfony already applies 301s to the initial production document request.
  // Vite and subsequent SPA links need the public resolver.
  if (from.name || import.meta.env.DEV) return resolvePublicRedirect(to)
})

export default router
