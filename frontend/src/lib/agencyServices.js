export const AGENCY_PLATFORMS = ['Instagram', 'TikTok', 'YouTube', 'Facebook', 'LinkedIn', 'Twitch', 'Spotify', 'Website']
export function normalizeServices(rows = []) {
  return rows.map((row, index) => typeof row === 'string'
    ? { id: `legacy-${index}`, title: row, description: '', deliverables: [], platforms: [], engagement: 'both' }
    : { description: '', deliverables: [], platforms: [], engagement: 'both', ...row })
}
export function newService() {
  return { id: crypto.randomUUID(), title: '', description: '', deliverables: [], platforms: [], engagement: 'both' }
}
export function proposalFromOffering(offering, currency, translate) {
  const scope = [offering.title, offering.description, ...(offering.deliverables || []).map(row => typeof row === 'string' ? `• ${row}` : `• ${row.quantity || 1} × ${translate(`studio.${row.format}`)}`)].filter(Boolean).join('\n').slice(0, 2000)
  return {
    scope,
    amount: offering.priceMode !== 'agreement' && offering.price && offering.currency === currency ? offering.price : null,
    timeline: offering.deliveryDays ? translate('studio.deliveryDescription', { count: offering.deliveryDays }) : '',
  }
}
