const measurementId = import.meta.env.VITE_GA_MEASUREMENT_ID?.trim()

let analyticsConsent = false
let analyticsInitialized = false

function initializeGoogleAnalytics() {
  if (!measurementId || analyticsInitialized) {
    return
  }

  window.dataLayer = window.dataLayer || []
  // Google processes gtag commands as Arguments objects, not data-layer arrays.
  window.gtag = function () { window.dataLayer.push(arguments) }
  window.gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' })
  window.gtag('js', new Date())
  window.gtag('config', measurementId, { send_page_view: false })

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`
  script.dataset.waveAnalytics = 'true'
  document.head.append(script)
  analyticsInitialized = true
}

export function setAnalyticsConsent(allowed) {
  analyticsConsent = allowed

  if (allowed) {
    initializeGoogleAnalytics()
    if (window.gtag) {
      window.gtag('consent', 'update', { analytics_storage: 'granted' })
    }
    trackPageView(window.location.pathname)
    return
  }

  if (window.gtag) {
    window.gtag('consent', 'update', { analytics_storage: 'denied' })
  }

  const hostParts = window.location.hostname.split('.')
  const domains = new Set([null])
  if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(window.location.hostname) && !window.location.hostname.includes(':')) {
    for (let index = 0; index < hostParts.length; index += 1) {
      const domain = hostParts.slice(index).join('.')
      if (domain.includes('.')) {
        domains.add(domain)
        domains.add(`.${domain}`)
      }
    }
  }
  for (const cookie of document.cookie.split(';')) {
    const name = cookie.split('=')[0]?.trim()
    if (name === '_ga' || name?.startsWith('_ga_')) {
      for (const domain of domains) {
        const domainAttribute = domain === null ? '' : `; domain=${domain}`
        document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax${domainAttribute}`
      }
    }
  }
}

export function trackPageView(path) {
  if (!analyticsConsent || !analyticsInitialized || !window.gtag) {
    return
  }

  const pageUrl = new URL(path, window.location.origin)
  window.gtag('event', 'page_view', {
    page_title: document.title,
    page_location: `${pageUrl.origin}${pageUrl.pathname}`,
    page_path: pageUrl.pathname,
  })
}
