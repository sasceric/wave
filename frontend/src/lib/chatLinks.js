// Return text nodes and safe web links; never interpret message content as HTML.
export function chatLinks(value = '') {
  const text = String(value)
  const parts = []
  const pattern = /(?:https?:\/\/|www\.)[^\s<>"']+|(?<![\w@/])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+[\p{L}]{2,63}(?:[/?#][^\s<>"']*)?/giu
  let cursor = 0
  for (const match of text.matchAll(pattern)) {
    let label = match[0].replace(/[.,!?;:]+$/u, '')
    for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']]) {
      while (label.endsWith(close) && label.split(close).length > label.split(open).length) label = label.slice(0, -1)
    }
    try {
      const url = new URL(/^https?:\/\//iu.test(label) ? label : `https://${label}`)
      if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || url.username || url.password) continue
      if (match.index > cursor) parts.push({ text: text.slice(cursor, match.index) })
      parts.push({ text: label, href: url.href })
      cursor = match.index + label.length
    } catch { /* Invalid URLs stay plain text. */ }
  }
  if (cursor < text.length) parts.push({ text: text.slice(cursor) })
  return parts
}
