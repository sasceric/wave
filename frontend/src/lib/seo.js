import { trackMetaPageView } from './metaTracking'
import i18n from '../i18n'
import breadcrumbParents from '../../../config/seo_breadcrumbs.json'
import { localizedPath, routeSegments, isSharedRoute } from '../routePaths'
import { regionalLanguageTags } from './regionalSeo'
import { pageTitle } from './pageTitle'
import { trackPageView } from './privacyMetrics'

const languageTags = {
  bs: 'bs',
  hr: 'hr',
  sr: 'sr-Latn',
  cnr: 'sr-Latn-ME',
  sl: 'sl',
  en: 'en',
  de: 'de',
}
const hreflangTags = { bs: 'bs', hr: 'hr', sr: 'sr-RS', cnr: 'sr-ME', sl: 'sl', en: 'en', de: 'de' }

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
  // A context-bearing graph avoids Safari's top-level JSON-LD array parser bug.
  element.textContent = JSON.stringify({ '@context': 'https://schema.org', '@graph': data })
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
  robots = null,
  image = null,
  mainEntity = null,
  analyticsReady = true,
}) {
  title = pageTitle(title)
  document.title = title
  document.documentElement.lang = languageTags[locale] || locale
  const origin = getSeoOrigin()
  const routeName = route.meta?.routeName
  const knownRoute = routeSegments[locale]?.[routeName] !== undefined
  const privatePage = route.meta?.accountSection || route.meta?.adminSection
    || routeName === 'account' || routeName?.startsWith('account-')
    || routeName === 'admin' || routeName?.startsWith('admin-')
    || ['messages', 'verify-email', 'reset-password', 'moderation', 'support-create', 'support-track'].includes(routeName)
  // Publish the page and its final title together. Never sample document.title
  // from a separate route watcher before this metadata update has run.
  trackPageView(route.path, { title, allowed: analyticsReady && !privatePage })
  trackMetaPageView(route.path, { allowed: analyticsReady && !privatePage && knownRoute && !noindex && routeName !== 'search' })
  setMeta('name', 'description', description)
  setMeta('name', 'robots', robots || (noindex || !knownRoute ? 'noindex, nofollow' : 'index, follow'))
  setMeta('property', 'og:type', 'website')
  setMeta('property', 'og:site_name', 'Wave')
  setMeta('property', 'og:title', title)
  setMeta('property', 'og:description', description)
  const verificationToken = import.meta.env.VITE_GOOGLE_SITE_VERIFICATION?.trim()
  if (verificationToken) {
    setMeta('name', 'google-site-verification', verificationToken)
  }
  setMeta('property', 'og:locale', ({
    bs: 'bs_BA',
    hr: 'hr_HR',
    sr: 'sr_RS',
    cnr: 'sr_ME',
    sl: 'sl_SI',
    en: 'en_US',
    de: 'de_DE',
  })[locale] || 'en_US')
  const hasShareImage = Boolean(image) || routeName === 'home'
  setMeta('name', 'twitter:card', hasShareImage ? 'summary_large_image' : 'summary')
  setMeta('name', 'twitter:title', title)
  setMeta('name', 'twitter:description', description)

  const canonicalUrl = knownRoute ? new URL(localizedPath(routeName, locale, route.params), origin).href : null
  if (canonicalUrl) {
    setCanonical(canonicalUrl)
    setMeta('property', 'og:url', canonicalUrl)
  } else {
    document.head.querySelector('link[rel="canonical"]')?.remove()
    document.head.querySelector('meta[property="og:url"]')?.remove()
  }

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
  if (!noindex && knownRoute && !isSharedRoute(routeName)) {
    for (const alternateLocale of Object.keys(routeSegments)) {
      const link = document.createElement('link')
      link.rel = 'alternate'
      link.hreflang = (routeName === 'country-creators' ? regionalLanguageTags : hreflangTags)[alternateLocale]
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

  if (noindex || !knownRoute) {
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
        urlTemplate: `${origin}${localizedPath('search', locale)}?q={search_term_string}`,
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
      availableLanguage: ['bs', 'hr', 'sr-Latn', 'sr-Latn-ME', 'sl', 'en', 'de'],
    },
  }
  // Vite and the API can use different local ports; keep profile IDs tied to
  // this page's canonical URL while leaving external images and links intact.
  if (mainEntity?.url && canonicalUrl) {
    const originalUrl = mainEntity.url
    mainEntity = JSON.parse(JSON.stringify(mainEntity, (key, value) => (
      ['url', '@id'].includes(key) && typeof value === 'string' && (value === originalUrl || value.startsWith(`${originalUrl}#`))
        ? canonicalUrl + value.slice(originalUrl.length) : value
    )))
  }
  const page = {
    '@context': 'https://schema.org',
    '@type': ['creators', 'companies', 'campaigns', 'country-creators', 'seo-guides', 'creator-guides'].includes(routeName)
      ? 'CollectionPage'
      : ['creator-profile', 'company-profile'].includes(routeName)
        ? 'ProfilePage'
        : routeName === 'support' ? 'ContactPage' : 'WebPage',
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
  if (routeName === 'home') {
    page.hasPart = { '@type': 'FAQPage', '@id': `${canonicalUrl}#faq`, mainEntity: Array.from({ length: 5 }, (_, index) => ({ '@type': 'Question', name: i18n.global.t(`siteFaq.question${index + 1}`), acceptedAnswer: { '@type': 'Answer', text: i18n.global.t(`siteFaq.answer${index + 1}`) } })) }
  }
  const nodes = []
  if (routeName !== 'home') {
    const crumbs = [{ '@type': 'ListItem', position: 1, name: i18n.global.t('app.home'), item: new URL(localizedPath('home', locale), origin).href }]
    const parent = breadcrumbParents[routeName]
    if (parent) crumbs.push({ '@type': 'ListItem', position: crumbs.length + 1, name: i18n.global.t(parent.label), item: new URL(localizedPath(parent.route, locale), origin).href })
    crumbs.push({ '@type': 'ListItem', position: crumbs.length + 1, name: title.replace(/\s*(?:[|—]\s*Wave)\s*$/u, ''), item: canonicalUrl })
    const breadcrumb = { '@context': 'https://schema.org', '@type': 'BreadcrumbList', '@id': `${canonicalUrl}#breadcrumbs`, itemListElement: crumbs }
    page.breadcrumb = { '@id': breadcrumb['@id'] }
    nodes.push(breadcrumb)
  }
  setStructuredData([website, organization, page, ...nodes])
}

// Update the review nodes after pagination without emitting another page view.
export function updateProfileReviewSchema(kind, slug, rows) {
  const element = document.getElementById('wave-schema')
  if (!element || !['creators', 'companies'].includes(kind)) return
  let nodes
  try {
    const schema = JSON.parse(element.textContent)
    // Accept the previous server format during a rolling deployment too.
    nodes = Array.isArray(schema) ? schema : schema?.['@graph']
  } catch { return }
  if (!Array.isArray(nodes)) return
  const url = new URL(localizedPath(kind === 'creators' ? 'creator-profile' : 'company-profile', 'bs', { slug }), getSeoOrigin()).href
  const entity = nodes.find(node => node['@type'] === 'ProfilePage' && node.url === url)?.mainEntity
  const target = kind === 'creators' ? entity?.makesOffer?.itemOffered : entity
  if (!target || entity.url !== url) return
  const reviews = rows.filter(row => !row.isPresentationDemo && row.comment && row.authorName).map(row => ({
    '@type': 'Review', '@id': `${url}#review-${row.id}`,
    author: { '@type': row.authorType || (kind === 'creators' ? 'Organization' : 'Person'), name: row.authorName },
    reviewBody: row.comment, datePublished: row.createdAt,
    reviewRating: { '@type': 'Rating', ratingValue: row.rating, bestRating: 5, worstRating: 1 },
  }))
  if (reviews.length) target.review = reviews
  else delete target.review
  setStructuredData(nodes)
}
