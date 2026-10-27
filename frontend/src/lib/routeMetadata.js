import titles from '../../../config/seo_route_titles.json'
import { routeSegments } from '../routePaths'

export function routeMetadata(route, locale, t, authenticated = false) {
  const name = route.meta.routeName
  const known = routeSegments[locale]?.[name] !== undefined
  const account = name === 'account' || name?.startsWith('account-')
  const privatePage = ['login', 'register', 'forgot-password'].includes(name) || account || name === 'admin' || name?.startsWith('admin-')
    || ['maintenance', 'messages', 'verify-email', 'reset-password', 'moderation', 'support-create', 'support-track'].includes(name)
  let key = (name === 'maintenance' ? 'seo.maintenanceTitle' : titles[name]) || (known ? 'seo.privateTitle' : 'seo.notFoundTitle')
  if (account && !authenticated) {
    key = ({ register: 'seo.registerTitle', reset: 'seo.forgotPasswordTitle' })[route.query?.mode] || 'seo.loginTitle'
  }
  const description = name === 'maintenance' ? 'maintenanceDescription' : name === 'search' ? 'searchDescription' : name === 'home' ? 'homeDescription'
    : name?.startsWith('creator') || name === 'creators' ? 'creatorsDescription'
      : name === 'companies' || name === 'company-profile' ? 'companiesDescription'
        : name === 'campaigns' || name === 'campaign-detail' ? 'campaignsDescription'
          : ({ imprint: 'imprintDescription', 'privacy-policy': 'privacyDescription', 'cookie-policy': 'cookiesDescription', support: 'supportDescription' })[name]
            || (known ? 'privateDescription' : 'notFoundDescription')
  const searchPage = name === 'search' || (['creators', 'companies', 'campaigns'].includes(name) && Boolean(route.query?.q?.trim?.()))
  const loadingProfile = ['creator-profile', 'company-profile', 'campaign-detail'].includes(name)
  return { title: t(key), description: t(`seo.${description}`), noindex: privatePage || !known || loadingProfile || searchPage, robots: searchPage ? 'noindex, follow' : undefined, analyticsReady: !loadingProfile && name !== 'maintenance' }
}
