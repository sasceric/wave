import sharedPublicRoutes from '../../config/shared_public_routes.json'
import routeSegments from '../../config/localized_routes.json'
import localePrefixes from '../../config/localized_route_prefixes.json'

export { routeSegments, localePrefixes }

export const defaultLocale = 'bs'
export const localizedRouteNames = new Set(Object.keys(routeSegments[defaultLocale]))

export function isSharedRoute(name) { return Object.hasOwn(sharedPublicRoutes, name) }

export function localizedRouteName(name, locale) {
  if (isSharedRoute(name)) return name
  return `${name}-${locale}`
}

export function localizedPath(name, locale, params = {}) {
  const segment = sharedPublicRoutes[name] ?? routeSegments[locale]?.[name] ?? routeSegments[defaultLocale][name]
  const prefix = !isSharedRoute(name) && localePrefixes[locale] ? `/${localePrefixes[locale]}` : ''
  if (!segment) return prefix ? `${prefix}/` : '/'

  return `${prefix}/${segment.replace(/:([A-Za-z0-9_]+)/g, (match, key) => (
    Object.hasOwn(params, key) ? encodeURIComponent(String(params[key])) : match
  ))}`
}

export function routeNameFromCanonicalPath(path) {
  const staticRoutes = {
    '/': 'home',
    '/account': 'account',
    '/login': 'login',
    '/register': 'register',
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

// Keep old component destinations compatible while emitting clean auth links.
export function authenticationDestination(to) {
  if (!to || typeof to !== 'object' || to.name !== 'account') return to
  const mode = to.query?.mode
  const name = ['login', 'register', 'reset'].includes(mode) ? (mode === 'reset' ? 'forgot-password' : mode) : null
  if (!name) return to
  const query = { ...to.query }
  delete query.mode
  return { ...to, name, query }
}

export function legacyLocalizedDetailPath(name, locale) {
  const prefix = localePrefixes[locale] ? `/${localePrefixes[locale]}` : ''
  return `${prefix}/${routeSegments[locale][name]}`
}
