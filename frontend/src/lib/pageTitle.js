export function pageTitle(title) {
  const text = String(title || '').trim()
  if (!text || text === 'Wave') return 'Wave'
  if (/^Wave\s*[|—–-]\s*/i.test(text)) return text.replace(/^Wave\s*[|—–-]\s*/i, 'Wave | ')
  return `${text.replace(/\s*[|—–-]\s*Wave$/i, '').trim()} | Wave`
}
