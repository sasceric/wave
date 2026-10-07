export const SOCIAL_PLATFORMS = ['TikTok', 'Instagram', 'YouTube']
export const COMPANY_SOCIAL_PLATFORMS = ['Instagram', 'TikTok', 'YouTube', 'Facebook', 'LinkedIn', 'Website']
export const CURRENCIES = ['BAM', 'EUR', 'RSD']
export const CREATOR_PLACEHOLDER = '/images/creator-placeholder.webp'
export const CAMPAIGN_PLACEHOLDER = '/images/share.webp'

export function campaignPlace(campaign, locale = 'bs') {
  const code = campaign.countryCode
  const language = locale === 'sr' || locale === 'cnr' ? 'sr-Latn' : locale === 'bs' ? 'hr' : locale
  const country = code ? new Intl.DisplayNames([language], { type: 'region' }).of(code) : ''
  return [campaign.city, country].filter(Boolean).join(', ')
}
