import routeSegments from '../../config/localized_routes.json'
import localePrefixes from '../../config/localized_route_prefixes.json'

export { routeSegments, localePrefixes }

export const defaultLocale = 'bs'
export const localizedRouteNames = new Set(Object.keys(routeSegments[defaultLocale]))

export function localizedRouteName(name, locale) {
  return `${name}-${locale}`
}

export function localizedPath(name, locale, params = {}) {
  const segment = routeSegments[locale]?.[name] ?? routeSegments[defaultLocale][name]
  const prefix = localePrefixes[locale] ? `/${localePrefixes[locale]}` : ''
  if (!segment) return prefix ? `${prefix}/` : '/'

  return `${prefix}/${segment.replace(/:([A-Za-z0-9_]+)/g, (match, key) => (
    Object.hasOwn(params, key) ? encodeURIComponent(String(params[key])) : match
  ))}`
}

export function routeNameFromCanonicalPath(path) {
  const staticRoutes = {
    '/': 'home',
    '/account': 'account',
    '/account/credits': 'account-credits',
    '/admin/credit-settings': 'admin-credit-settings',
    '/account/applications': 'account-applications',
    '/account/offers': 'account-offers',
    '/account/inquiries': 'account-inquiries',
    '/account/bookmarks': 'account-bookmarks',
    '/account/invitations': 'account-invitations',
    '/account/campaigns': 'account-campaigns',
    '/account/campaigns/new': 'account-campaign-create',
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
    '/admin/tools': 'admin-tools',
  }

  return staticRoutes[path]
}
