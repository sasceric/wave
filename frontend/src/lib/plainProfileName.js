// Compatibility normalization removes pasted font variants without stripping real accents or scripts.
export function plainProfileName(value) {
  return String(value ?? '').normalize('NFKC').trim()
}
