export function bucketOverviewSeries(series, maxBuckets = 8) {
  const size = Math.max(1, Math.ceil(series.length / maxBuckets))
  const buckets = []
  for (let index = 0; index < series.length; index += size) {
    const rows = series.slice(index, index + size)
    const bucket = { from: rows[0].date, to: rows.at(-1).date, creators: 0, campaigns: 0, applications: 0, invitations: 0, hires: 0, completed: 0 }
    for (const row of rows) for (const key of ['creators', 'campaigns', 'applications', 'invitations', 'hires', 'completed']) bucket[key] += row[key] || 0
    buckets.push(bucket)
  }
  return buckets
}

export function cumulativeOverviewSeries(series) {
  let creators = 0
  let campaigns = 0
  return series.map(row => ({ ...row, creators: creators += row.creators || 0, campaigns: campaigns += row.campaigns || 0 }))
}
