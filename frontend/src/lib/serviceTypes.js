// Service types with their own indexable page (/usluge/ugc-video). Mirrors ServicePackages::PAGE_SLUGS.
export const SERVICE_PAGE_SLUGS = { ugc: 'ugc-video', instagram: 'instagram', tiktok: 'tiktok', photo: 'fotografije' }

export function serviceTypeForSlug(slug) {
  return Object.keys(SERVICE_PAGE_SLUGS).find(type => SERVICE_PAGE_SLUGS[type] === slug) || ''
}

// The canonical place for a type filter: its own page when it has one, otherwise ?type= on /usluge.
export function serviceTypeLocation(type, query = {}) {
  const rest = { ...query }
  delete rest.type
  if (SERVICE_PAGE_SLUGS[type]) return { name: 'services-type', params: { type: SERVICE_PAGE_SLUGS[type] }, query: rest }
  return { name: 'services', query: type ? { ...rest, type } : rest }
}
