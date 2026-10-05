import { createRouter, createWebHistory } from 'vue-router'
import CampaignDetailView from './views/CampaignDetailView.vue'
import AccountView from './views/AccountView.vue'
import CampaignsView from './views/CampaignsView.vue'
import CompaniesView from './views/CompaniesView.vue'
import CompanyProfileView from './views/CompanyProfileView.vue'
import CreatorProfileView from './views/CreatorProfileView.vue'
import CreatorsView from './views/CreatorsView.vue'
import HomeView from './views/HomeView.vue'
import ModerationView from './views/ModerationView.vue'
import MessagesView from './views/MessagesView.vue'
import NotFoundView from './views/NotFoundView.vue'
import AdminDashboardView from './views/AdminDashboardView.vue'
import ResetPasswordView from './views/ResetPasswordView.vue'
import VerifyEmailView from './views/VerifyEmailView.vue'
import LegalView from './views/LegalView.vue'
import { localeNames, setLocale } from './i18n'
import { defaultLocale, localizedPath, localizedRouteName, routeSegments } from './routePaths'

const routeViews = {
  home: HomeView,
  account: AccountView,
  'account-applications': AccountView,
  'account-offers': AccountView,
  'account-inquiries': AccountView,
  'account-bookmarks': AccountView,
  'account-invitations': AccountView,
  'account-campaigns': AccountView,
  'account-campaign-create': AccountView,
  'account-campaign-detail': AccountView,
  messages: MessagesView,
  'verify-email': VerifyEmailView,
  'reset-password': ResetPasswordView,
  creators: CreatorsView,
  'creator-profile': CreatorProfileView,
  companies: CompaniesView,
  'company-profile': CompanyProfileView,
  campaigns: CampaignsView,
  'campaign-detail': CampaignDetailView,
  moderation: ModerationView,
  admin: AdminDashboardView,
  'admin-registrations': AdminDashboardView,
  'admin-homepage': AdminDashboardView,
  'admin-creators': AdminDashboardView,
  'admin-companies': AdminDashboardView,
  'admin-campaigns': AdminDashboardView,
  'admin-email-templates': AdminDashboardView,
  imprint: LegalView,
  'privacy-policy': LegalView,
  'cookie-policy': LegalView,
}

const adminSections = {
  admin: 'overview',
  'admin-registrations': 'registrations',
  'admin-homepage': 'homepage',
  'admin-creators': 'creators',
  'admin-companies': 'companies',
  'admin-campaigns': 'campaigns',
  'admin-email-templates': 'email-templates',
}

const accountSections = {
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
  Object.entries(routes).map(([name, segment]) => ({
    path: localizedPath(name, locale),
    name: localizedRouteName(name, locale),
    component: routeViews[name],
    meta: {
      locale,
      routeName: name,
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
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    ...localizedRoutes,
    ...legacyRoutes,
    ...Object.keys(localeNames).filter((locale) => locale !== defaultLocale).map((locale) => ({
      path: `/${locale}/:pathMatch(.*)*`,
      name: localizedRouteName('not-found', locale),
      component: NotFoundView,
      meta: { locale, routeName: 'not-found' },
    })),
    { path: '/:pathMatch(.*)*', name: 'legacy-not-found', component: NotFoundView },
  ],
})

router.beforeEach((to) => {
  if (to.meta.locale) {
    setLocale(to.meta.locale)
  }
})

export default router
