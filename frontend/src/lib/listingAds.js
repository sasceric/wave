export const AD_PALETTES = ['forest', 'coral', 'sage', 'paper', 'surface']
export const AD_KINDS = ['creators', 'companies', 'campaigns']

export function emptyListingAd(kind = 'creators') {
  return { kind, name: '', title: '', body: '', buttonLabel: '', url: '', helpLabel: '', helpUrl: '', palette: 'forest', span: 1, imageId: null, imageUrl: '', artwork: '', enabled: false, placements: [{ row: 2, column: 1 }] }
}

// Positions refer to the four-column desktop grid. Ads consume slots, never records.
// Recompute from all loaded records so loading another page preserves existing keys/order.
export function listingEntries(items, ads) {
  if (!items.length) return []
  const placements = ads.flatMap(ad => (ad.placements || []).map((place, index) => ({
    key: `ad-${ad.id}-${index}`, ad, slot: (place.row - 1) * 4 + place.column - 1,
  }))).sort((a, b) => a.slot - b.slot || a.key.localeCompare(b.key))
  const result = []
  let slot = 0
  let index = 0
  let next = 0
  let lastResultRow = -1
  while (index < items.length || (placements[next]?.slot === slot && Math.floor(slot / 4) === lastResultRow)) {
    while (next < placements.length && placements[next].slot < slot) next++
    const placement = placements[next]
    if (placement?.slot === slot) {
      result.push(placement)
      slot += placement.ad.span === 2 ? 2 : 1
      next++
    } else {
      lastResultRow = Math.floor(slot / 4)
      result.push({ key: `item-${items[index].id}`, item: items[index++] })
      slot++
    }
  }
  return result
}

// Fill the remaining phone column before a wide card, keeping result order intact.
export function fitListingEntries(entries, columns) {
  if (columns !== 2) return entries
  const pending = [...entries]
  const fitted = []
  let slot = 0
  while (pending.length) {
    if (pending[0].ad?.span === 2 && slot % 2 === 1) {
      const nextResult = pending.findIndex(entry => entry.item)
      if (nextResult >= 0) {
        fitted.push(pending.splice(nextResult, 1)[0])
        slot++
      }
    }
    const entry = pending.shift()
    fitted.push(entry)
    slot += entry.ad?.span || 1
  }
  return fitted
}
