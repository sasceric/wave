// The Pixel ID is public. Never put a Conversions API token in a VITE_ variable.
const pixelId = import.meta.env.VITE_META_PIXEL_ID?.trim() || ''
const enabled = import.meta.env.VITE_META_PIXEL_ENABLED === 'true' && /^\d{5,25}$/.test(pixelId)
const allowedHosts = (import.meta.env.VITE_META_PIXEL_HOSTS || 'wave.ba,www.wave.ba').split(',').map(host => host.trim()).filter(Boolean)
let consent = false
let initialized = false
let currentPage = null
let lastPage = null
const registrations = new Set()

function permittedLocation() {
  if (!enabled || !allowedHosts.includes(window.location.hostname)) return false
  const url = new URL(window.location.href)
  // Pixel also reads the browser URL. Never initialize/send on token, search,
  // email-link or arbitrary return URLs, even if the custom payload is safe.
  if (url.hash) return false
  for (const [key, value] of url.searchParams) {
    if (key === 'mode' && ['register', 'login'].includes(value)) continue
    if (key === 'accountType' && ['creator', 'company'].includes(value)) continue
    if (key === 'oauth' && value === 'complete') continue
    if (key === 'locale' && ['bs', 'hr', 'sr', 'sl', 'cnr', 'en', 'de'].includes(value)) continue
    if (/^(utm_(source|medium|campaign|content|term)|fbclid)$/.test(key) && /^[a-zA-Z0-9_.~-]{1,1024}$/.test(value)) continue
    return false
  }
  return true
}

function initialize() {
  if (!consent || !permittedLocation()) return false
  if (initialized) return true
  // Standard Meta loader queue. No automatic advanced matching or auto events.
  if (!window.fbq) {
    const fbq = function () {
      if (fbq.callMethod) fbq.callMethod.apply(fbq, arguments)
      else fbq.queue.push(arguments)
    }
    fbq.queue = []
    fbq.push = fbq
    fbq.loaded = true
    fbq.version = '2.0'
    window.fbq = fbq
    window._fbq = fbq
  }
  // Loading is already gated on marketing consent. A queued revoke would lock
  // Meta's initial queue drain before it can reach the following grant.
  window.fbq('set', 'autoConfig', false, pixelId)
  window.fbq('init', pixelId)
  window.fbq('consent', 'grant')
  const script = document.createElement('script')
  script.async = true
  script.src = 'https://connect.facebook.net/en_US/fbevents.js'
  script.dataset.waveMarketing = 'true'
  document.head.append(script)
  initialized = true
  return true
}

function clearCookies() {
  const domains = new Set([''])
  const parts = window.location.hostname.split('.')
  if (!/^\d+(\.\d+){3}$/.test(window.location.hostname)) {
    for (let i = 0; i < parts.length - 1; i++) {
      const domain = parts.slice(i).join('.')
      domains.add(domain)
      domains.add(`.${domain}`)
    }
  }
  for (const cookie of document.cookie.split(';')) {
    const name = cookie.split('=')[0]?.trim()
    if (!['_fbp', '_fbc'].includes(name)) continue
    for (const domain of domains) document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax${domain ? `; domain=${domain}` : ''}`
  }
}

function updateMarketingConsent(allowed) {
  consent = allowed === true
  if (!consent) {
    if (initialized && window.fbq) {
      // Revoke before the SDK finishes loading too: discard pending events/grants.
      if (!window.fbq.callMethod && Array.isArray(window.fbq.queue)) {
        window.fbq.queue = window.fbq.queue.filter(args => !['track', 'trackSingle', 'trackCustom', 'trackSingleCustom', 'consent'].includes(args[0]))
        window.fbq('consent', 'revoke')
        // Lock before processing init if consent changes while the SDK loads.
        window.fbq.queue.unshift(window.fbq.queue.pop())
      } else {
        window.fbq('consent', 'revoke')
      }
    }
    clearCookies()
    lastPage = null
    return
  }
  if (initialized && permittedLocation()) {
    // Before SDK startup, replace obsolete consent commands instead of leaving
    // a revoke ahead of the grant in Meta's lock-sensitive queue.
    if (!window.fbq.callMethod && Array.isArray(window.fbq.queue)) {
      window.fbq.queue = window.fbq.queue.filter(args => args[0] !== 'consent')
    }
    window.fbq('consent', 'grant')
  }
  sendPageView()
}

function updatePageView(path, { allowed = true } = {}) {
  currentPage = allowed && path ? path : null
  if (!currentPage) { lastPage = null; return }
  sendPageView()
}

function sendPageView() {
  if (!currentPage || !consent || currentPage === lastPage) return
  const path = new URL(currentPage, window.location.origin).pathname
  if (path !== window.location.pathname || !initialize()) return
  window.fbq('trackSingle', pixelId, 'PageView', { page_path: path })
  lastPage = currentPage
}

export function trackRegistration(user, method = 'email') {
  // Called only by a successful signup response, never login/session/profile save.
  // IDs are used in memory for deduplication only and are never sent to Meta.
  if (!user?.id || !['creator', 'company'].includes(user.accountType) || user.isPresentationDemo || user.isAdmin || user.isModerator || registrations.has(user.id) || !consent) return
  try {
    if (!initialize()) return
    const data = { content_name: user.accountType, account_type: user.accountType, status: true, registration_method: method === 'social' ? 'social' : 'email' }
    if (user.accountType === 'company' && ['brand', 'agency'].includes(user.profile?.companyType)) data.company_type = user.profile.companyType
    const eventId = window.crypto.randomUUID()
    window.fbq('trackSingle', pixelId, 'CompleteRegistration', data, { eventID: eventId })
    registrations.add(user.id)
  } catch {
    // An ad blocker, disabled SDK or tracking failure must never break signup.
  }
}


export function setMarketingConsent(allowed) {
  try { updateMarketingConsent(allowed) } catch { /* Optional tracking must not interrupt Wave. */ }
}

export function trackMetaPageView(path, options) {
  try { updatePageView(path, options) } catch { /* Ad blockers must not interrupt navigation. */ }
}
