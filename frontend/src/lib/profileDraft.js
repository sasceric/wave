import { toRaw } from 'vue'
import { parsePhoneNumberFromString } from 'libphonenumber-js/min'
import { normalizeServices } from './agencyServices.js'
import { normalizePresentation, presentationPayload } from './creatorPresentation.js'
import { formatInternationalPhoneNumber } from './phoneNumbers.js'

// The editable profile shape used by the account editor, built from the session user.
export function accountProfileDraft(user) {
  if (!user?.profile) return null
  // The session user is reactive; clone its raw data, not the proxy.
  const base = structuredClone(toRaw(user.profile))
  const company = user.accountType === 'company'
  const profile = {
    ...base,
    ...(company ? { companyType: base.companyType || 'brand', industries: base.industries || [base.industry].filter(Boolean), socialLinks: base.socialLinks || [] } : {}),
    ...(user.accountType === 'creator' ? { birthday: base.birthday || '', creatorTypes: base.creatorTypes || [] } : {}),
    phone: user.phone || '',
    city: user.city || '',
    countryCode: user.countryCode || '',
  }
  normalizePresentation(profile)
  profile.faqs ||= []
  profile.services = normalizeServices(profile.services)
  return profile
}

export function profilePhoneCountry(profile) {
  return parsePhoneNumberFromString(profile.phone || '')?.country || profile.countryCode || 'BA'
}

export function normalizedProfilePhone(profile, country = profilePhoneCountry(profile)) {
  const entered = (profile.phone || '').trim()
  return entered ? formatInternationalPhoneNumber(entered, country) : null
}

// Creator profiles replace every collection on save, so the body is always complete.
export function creatorProfileBody(profile, { tags = profile.tags || [], phone }) {
  return {
    displayName: profile.displayName,
    category: profile.categories[0] || profile.category,
    categories: profile.categories,
    creatorTypes: profile.creatorTypes,
    birthday: profile.birthday || null,
    phone,
    city: profile.city.trim(),
    countryCode: profile.countryCode,
    bio: profile.bio,
    tagline: profile.tagline,
    avatarMediaId: profile.avatarMediaId || null,
    avatarUrl: profile.avatarMediaId ? null : profile.avatarUrl || null,
    tags: [...tags],
    ...presentationPayload(profile),
    faqs: profile.faqs.map(({ question, answer }) => ({ question, answer })),
  }
}

export function companyProfileBody(profile, { phone, agency = profile.companyType === 'agency' }) {
  return {
    name: profile.name,
    companyType: profile.companyType,
    industries: profile.industries,
    coverMediaId: profile.coverMediaId || null,
    logoMediaId: profile.logoMediaId || null,
    logoUrl: profile.logoMediaId ? null : profile.logoUrl || null,
    about: profile.about || '',
    city: profile.city?.trim() || null,
    countryCode: profile.countryCode || null,
    phone,
    ...(agency ? { ...presentationPayload(profile), services: profile.services, faqs: profile.faqs } : {}),
    socialLinks: (profile.socialLinks || []).map(({ platform, url }) => ({ platform, url })),
  }
}

export function profileRequestBody(user, profile, options = {}) {
  const phone = options.phone ?? normalizedProfilePhone(profile)
  if (!phone) {
    const error = new Error('phone')
    error.code = 'phone'
    throw error
  }
  return user.accountType === 'creator'
    ? creatorProfileBody(profile, { ...options, phone })
    : companyProfileBody(profile, { ...options, phone })
}

// Plain text from the quick editor becomes the rich-text paragraphs the profile stores.
export function paragraphsHtml(text) {
  const escape = (value) => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  return text.trim().split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean)
    .map((part) => `<p>${escape(part).replace(/\n/g, '<br>')}</p>`).join('')
}

export function htmlToPlainText(html) {
  if (!html) return ''
  return html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>\s*<p[^>]*>/gi, '\n\n').replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&').trim()
}
