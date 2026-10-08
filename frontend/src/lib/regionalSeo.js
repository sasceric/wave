import countries from '../../../config/seo_countries.json'

export { countries }
export const guideSlugs = ['find-creators', 'choose-package', 'campaign-brief']
export const creatorGuideSlugs = ['create-profile', 'offer-packages', 'apply-to-campaigns']
export const regionalLanguageTags = { bs: 'bs-BA', hr: 'hr-HR', sr: 'sr-RS', cnr: 'sr-ME', sl: 'sl-SI', en: 'en' }

export function countryCodeForSlug(slug) {
  return Object.keys(countries).find(code => countries[code].slug === slug) || null
}

export function regionalMetadata(route, t) {
  if (route.meta.routeName === 'how-it-works') return { title: `${t('regionalSeo.howItWorks.title')} | Wave`, description: t('regionalSeo.howItWorks.intro') }
  if (route.meta.routeName === 'creator-guides') return { title: `${t('regionalSeo.creatorGuides.title')} | Wave`, description: t('regionalSeo.creatorGuides.intro') }
  if (route.meta.routeName === 'creator-guide') {
    const slug = route.params.guide
    return creatorGuideSlugs.includes(slug) ? { title: `${t(`regionalSeo.creatorGuides.guides.${slug}.title`)} | Wave`, description: t(`regionalSeo.creatorGuides.guides.${slug}.intro`) } : { invalid: true }
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
