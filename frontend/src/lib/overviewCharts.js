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

export function overviewScale(maximum) {
  if (maximum <= 4) return 4
  const step = 10 ** Math.floor(Math.log10(maximum))
  return Math.ceil(maximum / step) * step
}

// Horizontal control points stay inside each interval's values, avoiding
// invented peaks or negative counts between cumulative observations.
export function overviewCurve(series, key, maximum) {
  if (!series.length) return ''
  const points = series.map((row, index) => ({ x: 42 + index / Math.max(1, series.length - 1) * 536, y: 174 - row[key] / maximum * 142 }))
  let path = `M ${points[0].x} ${points[0].y}`
  for (let index = 1; index < points.length; index++) {
    const previous = points[index - 1]
    const point = points[index]
    const middle = (previous.x + point.x) / 2
    path += ` C ${middle} ${previous.y}, ${middle} ${point.y}, ${point.x} ${point.y}`
  }
  return path
}
