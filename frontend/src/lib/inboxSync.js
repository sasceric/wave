export function startInboxSync(refreshInbox, {
  windowTarget = window,
  documentTarget = document,
  serviceWorker = windowTarget.navigator?.serviceWorker,
  onError = (cause) => console.error('Unable to refresh the Wave inbox.', cause),
} = {}) {
  let stopped = false
  let refreshing = false
  let refreshPending = false

  async function refresh() {
    if (
      stopped
      || documentTarget.visibilityState !== 'visible'
      || windowTarget.navigator?.onLine === false
    ) {
      return
    }
    if (refreshing) {
      refreshPending = true
      return
    }

    refreshing = true
    try {
      await refreshInbox()
    } catch (cause) {
      if (!stopped) onError(cause)
    } finally {
      refreshing = false
      if (refreshPending) {
        refreshPending = false
        void refresh()
      }
    }
  }

  function handlePush(event) {
    if (event.data?.type === 'WAVE_PUSH_RECEIVED') {
      void refresh()
    }
  }

  windowTarget.addEventListener('focus', refresh)
  windowTarget.addEventListener('online', refresh)
  documentTarget.addEventListener('visibilitychange', refresh)
  serviceWorker?.addEventListener('message', handlePush)

  return {
    refresh,
    stop() {
      stopped = true
      windowTarget.removeEventListener('focus', refresh)
      windowTarget.removeEventListener('online', refresh)
      documentTarget.removeEventListener('visibilitychange', refresh)
      serviceWorker?.removeEventListener('message', handlePush)
    },
  }
}
