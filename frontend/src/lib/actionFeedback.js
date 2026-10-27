import i18n from '../i18n'
import { actionToasts } from './toasts'

export function hasActionFeedback(path, method = 'GET', feedback) {
  if (feedback === false) return false
  if (feedback === true || feedback && typeof feedback === 'object') return true
  if (['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) return false
  const endpoint = path.split('?')[0]
  return !(
    endpoint === '/admin/tools/worker/consume'
    || endpoint === '/me/push-subscriptions'
    || endpoint === '/support/tickets/track'
    || /^\/creators\/[^/]+\/view$/.test(endpoint)
    || /\/read$/.test(endpoint)
  )
}

export function actionFeedback(path, method, feedback, payload, error = null) {
  if (!hasActionFeedback(path, method, feedback) || error?.name === 'AbortError') return
  const settings = feedback && typeof feedback === 'object' ? feedback : {}
  if (error) {
    if (settings.error === false) return
    const message = typeof settings.error === 'string' ? settings.error
      : error instanceof TypeError ? i18n.global.t('actionFeedback.networkError')
        : error.message || i18n.global.t('actionFeedback.failed')
    actionToasts.notify(message, 'error')
  } else if (settings.success !== false) {
    const message = typeof settings.success === 'string' ? settings.success
      : typeof payload?.message === 'string' && payload.message ? payload.message
        : i18n.global.t('actionFeedback.success')
    actionToasts.notify(message, 'success')
  }
}
