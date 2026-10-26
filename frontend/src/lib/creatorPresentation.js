export const CONTENT_LANGUAGES = ['bs', 'hr', 'sr', 'sl', 'cnr', 'en', 'de', 'fr', 'it', 'es', 'tr', 'ar']
export const PRESENTATION_PLATFORMS = ['TikTok', 'Instagram', 'YouTube', 'Twitch', 'Spotify']
const number = value => value === '' || value === null || value === undefined ? null : Number(value)
export const REFERENCE_PLATFORMS = ['All', ...PRESENTATION_PLATFORMS, 'Facebook', 'LinkedIn', 'Pinterest', 'Other']
export const emptyReference = () => ({ type: 'video', platform: 'All', url: '', mediaId: null })
export function workReferences(work) {
  if (Array.isArray(work.references)) return work.references
  const rows = [work, ...(work.media || [])].filter(row => row.url || row.mediaId).map(row => ({ type: row.type, platform: row.platform || work.platform || 'All', url: row.url || '', mediaId: row.mediaId || null, embedUrl: row.embedUrl, legacyImage: row.type === 'image' && !row.mediaId }))
  if (work.postUrl && !rows.some(row => row.url === work.postUrl)) rows.push({ ...emptyReference(), type: work.type, platform: work.platform || 'All', url: work.postUrl })
  return rows
}
export const referenceImage = row => row.type === 'image' && (row.mediaId || row.legacyImage) ? row.imageUrl || row.url || '' : ''
export const workImage = work => work.coverUrl || workReferences(work).map(referenceImage).find(Boolean) || ''
export const emptyWork = () => ({ references: [emptyReference()], id: '', type: 'image', title: '', url: '', platform: 'All', summary: '', source: 'sample', campaignId: null, client: '', agency: '', brief: '', contribution: '', projectDate: '', postUrl: '', deliverables: [], results: [], measuredAt: '', featured: false, publicPermission: true, coverMediaId: null, media: [] })
export const emptyDelivery = () => ({ quantity: 1, format: 'video', duration: null })
export function normalizePresentation(profile) {
  profile.presentation = { languages: [], availability: 'unspecified', availableFrom: '', introVideoUrl: '', ...profile.presentation }
  profile.presentation.availableFrom ||= ''
  profile.portfolio = (profile.portfolio || []).map(item => ({ ...emptyWork(), publicPermission: true, ...item, references: workReferences(item).map(row => ({ ...row })) }))
  profile.socialProfiles = (profile.socialProfiles || []).map(item => ({ averageViews: null, sampleSize: 10, engagementRate: null, engagementBasis: 'followers', audienceCountries: [], ...item }))
  profile.packages = (profile.packages || []).map(item => ({ mode: 'posting', priceMode: item.price ? 'fixed' : 'agreement', deliveryDays: null, revisions: null, usage: 'agreement', usageDays: null, deliverables: [], ...item }))
  return profile
}
function deliveries(rows = []) { return rows.map(row => ({ ...row, quantity: number(row.quantity), duration: number(row.duration) })) }
export function presentationPayload(profile) {
  return {
    presentation: { ...profile.presentation, availableFrom: profile.presentation.availableFrom || null },
    socialProfiles: profile.socialProfiles.map(row => ({ ...row, followers: number(row.followers), averageViews: number(row.averageViews), sampleSize: number(row.sampleSize), engagementRate: number(row.engagementRate), audienceCountries: (row.audienceCountries || []).map(country => ({ ...country, percent: number(country.percent) })) })),
    portfolio: profile.portfolio.map(row => ({ ...row, references: workReferences(row).map(reference => ({ type: reference.type, platform: reference.platform || 'All', url: reference.url || '', ...(reference.mediaId ? { mediaId: Number(reference.mediaId) } : {}) })), mediaId: row.mediaId ? Number(row.mediaId) : undefined, coverMediaId: row.coverMediaId ? Number(row.coverMediaId) : null, campaignId: row.source === 'wave' ? number(row.campaignId) : null, deliverables: deliveries(row.deliverables), results: (row.results || []).map(result => ({ ...result, value: number(result.value) })), media: (row.media || []).map(media => ({ ...media, mediaId: media.mediaId ? Number(media.mediaId) : undefined })) })),
    packages: profile.packages.map(row => ({ ...row, price: row.priceMode === 'agreement' ? null : number(row.price), deliveryDays: number(row.deliveryDays), revisions: number(row.revisions), usageDays: row.usage === 'ads' ? number(row.usageDays) : null, deliverables: deliveries(row.deliverables) })),
  }
}
export function languageOptions(locale, translate) {
  const names = new Intl.DisplayNames([locale === 'cnr' ? 'bs' : locale === 'sr' ? 'sr-Latn' : locale], { type: 'language' })
  return CONTENT_LANGUAGES.map(value => ({ value, label: translate ? translate(`studio.contentLanguages.${value}`) : names.of(value) || value }))
}
