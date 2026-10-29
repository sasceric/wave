import countries from '../../../config/seo_countries.json'
import topics from '../../../config/seo_topics.json'

export { countries, topics }
export const guideSlugs = ['find-creators', 'choose-package', 'campaign-brief']
export const creatorGuideSlugs = ['create-profile', 'offer-packages', 'apply-to-campaigns']
export const regionalLanguageTags = { bs: 'bs-BA', hr: 'hr-HR', sr: 'sr-RS', cnr: 'sr-ME', sl: 'sl-SI', en: 'en', de: 'de' }

export function countryCodeForSlug(slug) {
  return Object.keys(countries).find(code => countries[code].slug === slug) || null
}

/** Landing page below a country: an area (ljepota), UGC creators (ugc-kreatori) or a city (sarajevo). */
export function topicForSlug(countryCode, slug) {
  if (!countryCode || typeof slug !== 'string') return null
  if (topics.categories[slug]) return { slug, kind: 'category', key: topics.categories[slug] }
  if (topics.creatorTypes[slug]) return { slug, kind: 'type', key: topics.creatorTypes[slug] }
  const city = topics.cities[countryCode]?.[slug]
  return city ? { slug, kind: 'city', key: slug, city } : null
}

/** Title, description and intro of a landing page, matching RegionalSeoContent::topicCopy(). */
export function topicCopy(t, locale, countryCode, topic) {
  let phrase = t(`regionalSeo.countries.${countryCode}.in`)
  let base = topic.kind === 'type' ? 'regionalSeo.topics.ugc' : `regionalSeo.topics.categories.${topic.key}`
  if (topic.kind === 'city') {
    const group = ['bs', 'hr', 'sr', 'cnr'].includes(locale) ? 'bhs' : locale
    phrase = topic.city.in[group] || (['en', 'de'].includes(locale) ? `in ${topic.city.name}` : topic.city.name)
    base = 'regionalSeo.topics.city'
  }
  return {
    title: t(`${base}.title`, { in: phrase }),
    description: t(`${base}.description`, { in: phrase }),
    intro: t(`${base}.intro`, { in: phrase }),
  }
}

export function regionalMetadata(route, t) {
  if (route.meta.routeName === 'how-it-works') return { title: `${t('regionalSeo.howItWorks.title')} | Wave`, description: t('regionalSeo.howItWorks.intro') }
  if (route.meta.routeName === 'creator-guides') return { title: `${t('regionalSeo.creatorGuides.title')} | Wave`, description: t('regionalSeo.creatorGuides.intro') }
  if (route.meta.routeName === 'creator-guide') {
    const slug = route.params.guide
    return creatorGuideSlugs.includes(slug) ? { title: `${t(`regionalSeo.creatorGuides.guides.${slug}.title`)} | Wave`, description: t(`regionalSeo.creatorGuides.guides.${slug}.intro`) } : { invalid: true }
  }
  if (route.meta.routeName === 'country-topic') {
    const code = countryCodeForSlug(route.params.country)
    const topic = topicForSlug(code, route.params.topic)
    if (!topic) return { invalid: true }
    const copy = topicCopy(t, route.meta.locale, code, topic)
    return { title: `${copy.title} | Wave`, description: copy.description }
  }
  if (route.meta.routeName === 'country-creators') {
    const code = countryCodeForSlug(route.params.country)
    return code ? { title: `${t(`regionalSeo.countries.${code}.title`)} | Wave`, description: t(`regionalSeo.countries.${code}.description`) } : { invalid: true }
  }
  if (route.meta.routeName === 'seo-guide') {
    const slug = route.params.guide
    return guideSlugs.includes(slug) ? { title: `${t(`regionalSeo.guides.${slug}.title`)} | Wave`, description: t(`regionalSeo.guides.${slug}.intro`) } : { invalid: true }
  }
  if (route.meta.routeName === 'seo-guides') return { title: `${t('regionalSeo.guideTitle')} | Wave`, description: t('regionalSeo.guideIntro') }
  return null
}

export function normalizedCity(value) {
  return String(value || '').split(',')[0].trim().toLowerCase()
    .replace(/[čć]/g, 'c').replace(/ž/g, 'z').replace(/š/g, 's').replace(/đ/g, 'dj').replace(/[\s-]+/g, ' ')
}

/** The populated landing page a creator's city, area or UGC type belongs to, if any. */
export function creatorLandingPages(items, countryCode, creator) {
  const country = countries[countryCode]
  if (!country || !creator) return {}
  const link = item => item && { slug: item.slug, country: country.slug }
  const city = normalizedCity(creator.city)
  const cityItem = items.find(item => item.kind === 'city' && (topics.cities[countryCode]?.[item.slug]?.aliases || []).some(alias => normalizedCity(alias) === city))
  const categoryValues = [creator.category, ...(creator.categories || [])].filter(Boolean).map(value => String(value).toLowerCase())
  const categoryItem = items.find(item => item.kind === 'category' && categoryValues.includes(item.key))
  const ugcItem = items.find(item => item.kind === 'type' && (creator.creatorTypes || []).includes(item.key))
  return { city: link(cityItem), category: link(categoryItem), ugc: link(ugcItem) }
}
