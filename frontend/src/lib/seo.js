import { localizedPath, routeSegments } from '../routePaths'

const languageTags = {
  bs: 'bs',
  hr: 'hr',
  sr: 'sr-Latn',
  cnr: 'cnr-Latn-ME',
  sl: 'sl',
  en: 'en',
}

function setMeta(attribute, key, value) {
  let element = document.head.querySelector(`meta[${attribute}="${key}"]`)
  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attribute, key)
    document.head.append(element)
  }
  element.setAttribute('content', value)
}

function setCanonical(url) {
  let element = document.head.querySelector('link[rel="canonical"]')
  if (!element) {
    element = document.createElement('link')
    element.rel = 'canonical'
    document.head.append(element)
  }
  element.href = url
}

function setStructuredData(data) {
  let element = document.getElementById('wave-schema')
  if (!data.length) {
    element?.remove()
    return
  }
  if (!element) {
    element = document.createElement('script')
    element.id = 'wave-schema'
    element.type = 'application/ld+json'
    document.head.append(element)
  }
  element.textContent = JSON.stringify(data)
}

export function getSeoOrigin() {
  return document.head.querySelector('meta[name="wave:origin"]')?.content || window.location.origin
}

export function updateSeo({
  route,
  locale,
  title,
  description,
  noindex = false,
  image = null,
  mainEntity = null,
}) {
  document.title = title
  document.documentElement.lang = languageTags[locale] || locale
  const origin = getSeoOrigin()
  setMeta('name', 'description', description)
  setMeta('name', 'robots', noindex ? 'noindex, nofollow' : 'index, follow')
  setMeta('property', 'og:type', 'website')
  setMeta('property', 'og:site_name', 'Wave')
  setMeta('property', 'og:title', title)
  setMeta('property', 'og:description', description)
  const routeName = route.meta?.routeName
  const verificationToken = import.meta.env.VITE_GOOGLE_SITE_VERIFICATION?.trim()
  if (verificationToken) {
    setMeta('name', 'google-site-verification', verificationToken)
  }
  setMeta('property', 'og:locale', ({
    bs: 'bs_BA',
    hr: 'hr_HR',
    sr: 'sr_RS',
    cnr: 'cnr_ME',
    sl: 'sl_SI',
    en: 'en_US',
  })[locale] || 'en_US')
  const hasShareImage = Boolean(image) || routeName === 'home'
  setMeta('name', 'twitter:card', hasShareImage ? 'summary_large_image' : 'summary')
  setMeta('name', 'twitter:title', title)
  setMeta('name', 'twitter:description', description)

  const canonicalPath = routeSegments[locale]?.[routeName] !== undefined
    ? localizedPath(routeName, locale, route.params)
    : route.path
  const canonicalUrl = new URL(canonicalPath, origin).href
  setCanonical(canonicalUrl)
  setMeta('property', 'og:url', canonicalUrl)

  const fallbackImage = routeName === 'home'
    ? '/images/share.webp'
    : ['creator-profile', 'company-profile'].includes(routeName)
      ? '/images/logo-icon.svg'
      : '/pwa-512.png'
  const ogImage = image ? new URL(image, origin).href : new URL(fallbackImage, origin).href
  setMeta('property', 'og:image', ogImage)
  setMeta('name', 'twitter:image', ogImage)
  const alternates = document.head.querySelectorAll('link[rel="alternate"][hreflang]')
  alternates.forEach((element) => element.remove())
  if (!noindex && routeSegments[locale]?.[routeName] !== undefined) {
    for (const alternateLocale of Object.keys(routeSegments)) {
      const link = document.createElement('link')
      link.rel = 'alternate'
      link.hreflang = languageTags[alternateLocale]
      link.href = new URL(localizedPath(routeName, alternateLocale, route.params), origin).href
      link.dataset.waveHreflang = 'true'
      document.head.append(link)
    }
    const defaultLink = document.createElement('link')
    defaultLink.rel = 'alternate'
    defaultLink.hreflang = 'x-default'
    defaultLink.href = new URL(localizedPath(routeName, 'bs', route.params), origin).href
    defaultLink.dataset.waveHreflang = 'true'
    document.head.append(defaultLink)
  }

  if (noindex) {
    setStructuredData([])
    return
  }

  const websiteUrl = new URL('/', origin).href
  const website = {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': `${websiteUrl}#website`,
    url: websiteUrl,
    name: 'Wave',
    inLanguage: languageTags[locale] || locale,
    publisher: { '@id': `${websiteUrl}#organization` },
    potentialAction: {
      '@type': 'SearchAction',
      target: {
        '@type': 'EntryPoint',
        urlTemplate: `${origin}${localizedPath('creators', locale)}?q={search_term_string}`,
      },
      'query-input': 'required name=search_term_string',
    },
  }
  const organization = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    '@id': `${websiteUrl}#organization`,
    name: 'UD SteelCode',
    legalName: 'UD SteelCode',
    alternateName: 'SteelCode',
    url: websiteUrl,
    logo: new URL('/pwa-512.png', origin).href,
    email: 'info@wave.ba',
    taxID: '4320531730002',
    vatID: '320531730002',
    address: {
      '@type': 'PostalAddress',
      streetAddress: 'Školska 10',
      addressLocality: 'Zenica',
      postalCode: '72000',
      addressCountry: 'BA',
    },
    brand: {
      '@type': 'Brand',
      name: 'Wave',
      url: websiteUrl,
    },
    contactPoint: {
      '@type': 'ContactPoint',
      contactType: 'customer support',
      email: 'info@wave.ba',
      availableLanguage: ['bs', 'hr', 'sr-Latn', 'cnr-Latn-ME', 'sl', 'en'],
    },
  }
  const page = {
    '@context': 'https://schema.org',
    '@type': ['creators', 'companies', 'campaigns'].includes(routeName)
      ? 'CollectionPage'
      : ['creator-profile', 'company-profile'].includes(routeName)
        ? 'ProfilePage'
        : 'WebPage',
    '@id': `${canonicalUrl}#webpage`,
    url: canonicalUrl,
    name: title,
    description,
    inLanguage: languageTags[locale] || locale,
    isPartOf: { '@id': `${websiteUrl}#website` },
    ...(mainEntity
      ? {
          mainEntity: {
            ...mainEntity,
            ...(typeof mainEntity.image === 'string' ? { image: new URL(mainEntity.image, origin).href } : {}),
          },
        }
      : {}),
  }
  setStructuredData([website, organization, page])
}
