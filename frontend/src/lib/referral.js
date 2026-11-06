// Pozovi kreatore: wave.ba/?ref=CODE is remembered for 30 days so it still counts when the
// visitor registers later, including through Google or Apple.
const KEY = 'wave-referral'
const LIFETIME = 30 * 24 * 60 * 60 * 1000

export function normalizeReferralCode(value) {
  const code = String(value ?? '').trim().toUpperCase()
  return /^[A-Z0-9]{3,24}$/.test(code) ? code : ''
}

export function rememberReferral(value, storage = globalThis.localStorage, now = Date.now()) {
  const code = normalizeReferralCode(value)
  if (!code) return ''
  try { storage?.setItem(KEY, JSON.stringify({ code, at: now })) } catch { /* Private mode: the code still works on this page. */ }
  return code
}

export function storedReferral(storage = globalThis.localStorage, now = Date.now()) {
  try {
    const saved = JSON.parse(storage?.getItem(KEY) || 'null')
    if (saved && normalizeReferralCode(saved.code) && now - Number(saved.at) < LIFETIME) return saved.code
  } catch { /* Unreadable storage counts as no referral. */ }
  return ''
}

export function forgetReferral(storage = globalThis.localStorage) {
  try { storage?.removeItem(KEY) } catch { /* Nothing to clear. */ }
}
