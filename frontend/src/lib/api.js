import i18n from '../i18n'

let csrfToken = ''

function intlLocale() {
  const locale = i18n.global.locale.value
  if (locale === 'sr') return 'sr-Latn'
  if (locale === 'cnr') return 'sr-Latn-ME'

  return locale
}

function localizedPath(path, locale = i18n.global.locale.value) {
  const separator = path.includes('?') ? '&' : '?'
  return `${path}${separator}locale=${encodeURIComponent(locale)}`
}

async function getCsrfToken(locale = i18n.global.locale.value) {
  const response = await fetch(localizedPath('/api/auth/csrf', locale), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    cache: 'no-store',
  })
  const payload = await response.json()
  if (!response.ok || typeof payload.csrfToken !== 'string') {
    throw new Error(payload.error || i18n.global.t('api.invalidResponse'))
  }
  csrfToken = payload.csrfToken
}

export async function apiRequest(path, { method = 'GET', body, locale } = {}) {
  const unsafe = !['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())
  if (unsafe && !csrfToken) await getCsrfToken(locale)

  const multipart = typeof FormData !== 'undefined' && body instanceof FormData
  const headers = { Accept: 'application/json' }
  if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json'
  if (unsafe) headers['X-CSRF-Token'] = csrfToken

  const response = await fetch(localizedPath(`/api${path}`, locale), {
    method,
    headers,
    credentials: 'same-origin',
    cache: path.startsWith('/auth/') ? 'no-store' : 'default',
    body: body === undefined ? undefined : multipart ? body : JSON.stringify(body),
  })

  let payload
  try {
    payload = await response.json()
  } catch {
    throw new Error(i18n.global.t('api.invalidResponse'))
  }

  if (!response.ok) {
    if (response.status === 403 && unsafe) csrfToken = ''
    const error = new Error(payload.error || i18n.global.t('api.requestFailed', { status: response.status }))
    error.status = response.status
    error.fields = Array.isArray(payload.fields) ? payload.fields : []
    throw error
  }

  if (typeof payload.csrfToken === 'string') csrfToken = payload.csrfToken
  else if (path === '/auth/logout' || path === '/me/account' && method.toUpperCase() === 'DELETE') csrfToken = ''

  return payload
}

export async function apiUpload(path, file) {
  if (!csrfToken) await getCsrfToken()
  const formData = new FormData()
  formData.append('file', file)

  const response = await fetch(localizedPath(`/api${path}`), {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    credentials: 'same-origin',
    body: formData,
  })

  let payload
  try {
    payload = await response.json()
  } catch {
    throw new Error(i18n.global.t('api.invalidResponse'))
  }

  if (!response.ok) {
    if (response.status === 403) csrfToken = ''
    const error = new Error(payload.error || i18n.global.t('api.requestFailed', { status: response.status }))
    error.status = response.status
    throw error
  }

  if (typeof payload.csrfToken === 'string') csrfToken = payload.csrfToken

  return payload
}

export async function apiGet(path, options) {
  return apiRequest(path, options)
}

export function formatFollowers(value) {
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(value % 1_000_000 === 0 ? 0 : 1)}m`
  if (value >= 1_000) return `${(value / 1_000).toFixed(value % 1_000 === 0 ? 0 : 1)}k`
  return String(value)
}

export function formatDate(value) {
  const dateOnly = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
  const date = new Date(dateOnly ? `${value}T12:00:00` : value)
  const parts = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).formatToParts(date)
  const part = (type) => parts.find((item) => item.type === type)?.value

  return `${part('day')}.${part('month')}.${part('year')}`
}

export function formatMoney(value, currency = 'BAM') {
  if (currency === 'BAM') {
    const formattedValue = new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: 0 }).format(value)
    return `${formattedValue} KM`
  }

  return new Intl.NumberFormat(intlLocale(), { style: 'currency', currency, maximumFractionDigits: 0 }).format(value)
}
