export const CONTENT_LANGUAGES = ['bs', 'hr', 'sr', 'sl', 'cnr', 'en', 'de', 'fr', 'it', 'es', 'tr', 'ar']
export const PRESENTATION_PLATFORMS = ['TikTok', 'Instagram', 'YouTube', 'Twitch', 'Spotify']
const number = value => value === '' || value === null || value === undefined ? null : Number(value)
export const emptyWork = () => ({ id: '', type: 'image', title: '', url: '', platform: 'All', summary: '', source: 'sample', campaignId: null, client: '', agency: '', brief: '', contribution: '', projectDate: '', postUrl: '', deliverables: [], results: [], measuredAt: '', featured: false, publicPermission: false, coverMediaId: null, media: [] })
export const emptyDelivery = () => ({ quantity: 1, format: 'video', duration: null })
export function normalizePresentation(profile) {
  profile.presentation = { languages: [], availability: 'unspecified', availableFrom: '', introVideoUrl: '', ...profile.presentation }
  profile.presentation.availableFrom ||= ''
  profile.portfolio = (profile.portfolio || []).map(item => ({ ...emptyWork(), publicPermission: true, ...item }))
  profile.socialProfiles = (profile.socialProfiles || []).map(item => ({ averageViews: null, sampleSize: 10, engagementRate: null, engagementBasis: 'followers', audienceCountries: [], ...item }))
  profile.packages = (profile.packages || []).map(item => ({ mode: 'posting', priceMode: item.price ? 'fixed' : 'agreement', deliveryDays: null, revisions: null, usage: 'agreement', usageDays: null, deliverables: [], ...item }))
  return profile
}
function deliveries(rows = []) { return rows.map(row => ({ ...row, quantity: number(row.quantity), duration: number(row.duration) })) }
export function presentationPayload(profile) {
  return {
    presentation: { ...profile.presentation, availableFrom: profile.presentation.availableFrom || null },
    socialProfiles: profile.socialProfiles.map(row => ({ ...row, followers: number(row.followers), averageViews: number(row.averageViews), sampleSize: number(row.sampleSize), engagementRate: number(row.engagementRate), audienceCountries: (row.audienceCountries || []).map(country => ({ ...country, percent: number(country.percent) })) })),
    portfolio: profile.portfolio.map(row => ({ ...row, mediaId: row.mediaId ? Number(row.mediaId) : undefined, coverMediaId: row.coverMediaId ? Number(row.coverMediaId) : null, campaignId: row.source === 'wave' ? number(row.campaignId) : null, deliverables: deliveries(row.deliverables), results: (row.results || []).map(result => ({ ...result, value: number(result.value) })), media: (row.media || []).map(media => ({ ...media, mediaId: media.mediaId ? Number(media.mediaId) : undefined })) })),
    packages: profile.packages.map(row => ({ ...row, price: row.priceMode === 'agreement' ? null : number(row.price), deliveryDays: number(row.deliveryDays), revisions: number(row.revisions), usageDays: row.usage === 'ads' ? number(row.usageDays) : null, deliverables: deliveries(row.deliverables) })),
  }
}
export function languageOptions(locale, translate) {
  const names = new Intl.DisplayNames([locale === 'cnr' ? 'bs' : locale === 'sr' ? 'sr-Latn' : locale], { type: 'language' })
  return CONTENT_LANGUAGES.map(value => ({ value, label: translate ? translate(`studio.contentLanguages.${value}`) : names.of(value) || value }))
}
