import routeSegments from '../../config/localized_routes.json'

export { routeSegments }

export const defaultLocale = 'bs'
export const localizedRouteNames = new Set(Object.keys(routeSegments[defaultLocale]))

export function localizedRouteName(name, locale) {
  return `${name}-${locale}`
}

export function localizedPath(name, locale, params = {}) {
  const segment = routeSegments[locale]?.[name] ?? routeSegments[defaultLocale][name]
  const prefix = locale === defaultLocale ? '' : `/${locale}`
  if (!segment) return prefix ? `${prefix}/` : '/'

  return `${prefix}/${segment.replace(/:([A-Za-z0-9_]+)/g, (match, key) => (
    Object.hasOwn(params, key) ? encodeURIComponent(String(params[key])) : match
  ))}`
}

export function routeNameFromCanonicalPath(path) {
  const staticRoutes = {
    '/': 'home',
    '/account': 'account',
    '/account/applications': 'account-applications',
    '/account/offers': 'account-offers',
    '/account/inquiries': 'account-inquiries',
    '/account/bookmarks': 'account-bookmarks',
    '/account/campaigns': 'account-campaigns',
    '/messages': 'messages',
    '/verify-email': 'verify-email',
    '/reset-password': 'reset-password',
    '/creators': 'creators',
    '/companies': 'companies',
    '/campaigns': 'campaigns',
    '/moderation': 'moderation',
    '/admin': 'admin',
    '/admin/registrations': 'admin-registrations',
    '/admin/pocetna-stranica': 'admin-homepage',
    '/admin/kreatori': 'admin-creators',
    '/admin/kompanije': 'admin-companies',
    '/admin/kampanje': 'admin-campaigns',
    '/admin/email-templates': 'admin-email-templates',
  }

  return staticRoutes[path]
}
