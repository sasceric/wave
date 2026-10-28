// Keep ties in saved platform order. Missing counts must never appear as zero.
export function largestSocialProfile(profiles = []) {
  let largest = null
  for (const profile of profiles || []) {
    const raw = profile?.followers
    if (!(typeof raw === 'number' && Number.isInteger(raw) && raw >= 0 && raw <= 9999999999)
      && !(typeof raw === 'string' && /^[0-9]{1,10}$/.test(raw))) continue
    const followers = Math.min(Number(raw), 2147483647)
    if (!largest || followers > largest.followers) largest = { ...profile, followers }
  }
  return largest
}
