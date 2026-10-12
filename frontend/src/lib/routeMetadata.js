import titles from '../../../config/seo_route_titles.json'
import { routeSegments } from '../routePaths'

export function routeMetadata(route, locale, t, authenticated = false) {
  const name = route.meta.routeName
  const known = routeSegments[locale]?.[name] !== undefined
  const account = name === 'account' || name?.startsWith('account-')
  const privatePage = account || name === 'admin' || name?.startsWith('admin-')
    || ['messages', 'verify-email', 'reset-password', 'moderation', 'support-create', 'support-track'].includes(name)
  let key = titles[name] || (known ? 'seo.privateTitle' : 'seo.notFoundTitle')
  if (account && !authenticated) {
    key = ({ register: 'seo.registerTitle', reset: 'seo.forgotPasswordTitle' })[route.query?.mode] || 'seo.loginTitle'
  }
  const description = name === 'home' ? 'homeDescription'
    : name?.startsWith('creator') || name === 'creators' ? 'creatorsDescription'
      : name === 'companies' || name === 'company-profile' ? 'companiesDescription'
        : name === 'campaigns' || name === 'campaign-detail' ? 'campaignsDescription'
          : ({ imprint: 'imprintDescription', 'privacy-policy': 'privacyDescription', 'cookie-policy': 'cookiesDescription', support: 'supportDescription' })[name]
            || (known ? 'privateDescription' : 'notFoundDescription')
  const loadingProfile = ['creator-profile', 'company-profile', 'campaign-detail'].includes(name)
  return { title: t(key), description: t(`seo.${description}`), noindex: privatePage || !known || loadingProfile }
}
