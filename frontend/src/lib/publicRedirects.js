import { apiGet } from './api'

export async function resolvePublicRedirect(route, timeoutMs = 5000) {
  const name = route.meta?.routeName || ''
  if (name === 'account' || name.startsWith('account-') || name === 'admin' || name.startsWith('admin-')
    || ['maintenance', 'login', 'register', 'forgot-password', 'messages', 'verify-email', 'reset-password', 'moderation', 'support-create', 'support-track'].includes(name)) return
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  try {
    const query = route.fullPath.split('#')[0]
    const result = await apiGet(`/redirects/resolve?${new URLSearchParams({ path: query })}`, { signal: controller.signal })
    const target = result.data?.targetPath
    if (typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') && target !== query) {
      return { path: target, replace: true }
    }
  } catch {
    // A failed redirect lookup must not prevent normal page navigation.
  } finally {
    clearTimeout(timer)
  }
}
