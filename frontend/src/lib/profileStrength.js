// Profile strength: an encouragement score on top of the required basics.
// Weights add up to 100 per account type; order is the order steps are suggested.
const STEPS = {
  creator: [
    ['photo', 20], ['works', 20], ['bio', 15], ['social', 15], ['packages', 15], ['audience', 10], ['faq', 5],
  ],
  agency: [
    ['logo', 15], ['about', 15], ['services', 15], ['works', 15], ['packages', 10], ['cover', 10], ['links', 10], ['faq', 10],
  ],
  brand: [
    ['logo', 25], ['about', 25], ['links', 20], ['cover', 15], ['industries', 15],
  ],
}

// Steps and their points for one account type, in suggestion order.
export function profileSteps(kind) {
  return (STEPS[kind] || []).map(([key, points]) => ({ key, points }))
}

export const BIO_MIN_LENGTH = 150
export const ABOUT_MIN_LENGTH = 80
export const MIN_WORKS = 3

export function plainTextLength(html) {
  if (typeof html !== 'string' || !html) return 0
  return html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&[a-z]+;|&#\d+;/gi, 'x')
    .replace(/\s+/g, ' ')
    .trim().length
}

export function profileKind(user) {
  if (user?.accountType === 'creator') return 'creator'
  if (user?.accountType === 'company') return user.profile?.companyType === 'agency' ? 'agency' : 'brand'
  return null
}

function checks(kind, profile) {
  const list = (value) => (Array.isArray(value) ? value : [])
  const social = list(profile.socialProfiles)
  return {
    photo: Boolean(profile.avatarUrl || profile.avatarMediaId),
    logo: Boolean(profile.logoUrl || profile.logoMediaId),
    cover: Boolean(profile.coverUrl || profile.coverMediaId),
    works: list(profile.portfolio).length >= MIN_WORKS,
    bio: plainTextLength(profile.bio) >= BIO_MIN_LENGTH,
    about: plainTextLength(profile.about) >= ABOUT_MIN_LENGTH,
    social: social.some((row) => String(row?.handle || '').trim() && Number(row?.followers) > 0),
    audience: social.some((row) => list(row?.audienceCountries).length > 0 || Number(row?.averageViews) > 0 || Number(row?.engagementRate) > 0),
    packages: kind === 'creator'
      ? list(profile.packages).some((row) => Number(row?.price) > 0)
      : list(profile.packages).length > 0,
    services: list(profile.services).length > 0,
    links: list(profile.socialLinks).some((row) => String(row?.url || '').trim()),
    industries: list(profile.industries).length > 0,
    faq: list(profile.faqs).length > 0,
  }
}

export function profileStrength(user) {
  const kind = profileKind(user)
  if (!kind || !user.profile) return null
  const done = checks(kind, user.profile)
  const steps = STEPS[kind].map(([key, points]) => ({ key, points, done: done[key] }))
  const score = steps.reduce((total, step) => total + (step.done ? step.points : 0), 0)
  const level = score >= 100 ? 4 : score >= 70 ? 3 : score >= 40 ? 2 : 1
  const next = [40, 70, 100].find((threshold) => threshold > score) ?? null
  return { kind, score, level, next, steps, missing: steps.filter((step) => !step.done) }
}

// Score to flag in navigation (dot on Profil / Ja), or null when nothing is missing
// or the account is still in activation (that flow has its own checklist).
export function incompleteProfileScore(user) {
  if (!user || user.isAdmin || user.isModerator || !user.approved || user.profileComplete === false) return null
  const strength = profileStrength(user)
  return strength && strength.score < 100 ? strength.score : null
}
