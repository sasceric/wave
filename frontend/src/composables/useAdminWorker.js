import { onBeforeUnmount, watch } from 'vue'
import { apiGet, apiRequest } from '../lib/api'

// HTTP executes Symfony's handlers only while an administrator is in the admin.
export function useAdminWorker(user, route) {
  let generation = 0
  let timer = null
  let disposed = false
  let releaseLeader = null

  const eligible = () => !disposed && user.value?.isAdmin && route.meta.adminSection && !document.hidden
  async function consume(ticket, interval) {
    if (ticket !== generation || !eligible()) return
    let delay = interval
    try {
      const run = async () => {
        if (ticket !== generation || !eligible()) return
        const result = await apiRequest('/admin/tools/worker/consume', { method: 'POST' })
        delay = result.data.handled > 0 ? 100 : interval
      }
      await run()
    } catch (error) {
      if ([401, 403].includes(error.status)) {
        releaseLeader?.()
        releaseLeader = null
        return
      }
      delay = Math.max(10000, interval)
    }
    if (ticket === generation && eligible()) timer = setTimeout(() => consume(ticket, interval), delay)
  }

  function lead(ticket, interval) {
    if (ticket !== generation || !eligible()) return
    if (!navigator.locks?.request) {
      consume(ticket, interval)
      return
    }
    // Hold leadership for the visible admin session, rather than per request.
    navigator.locks.request('wave-admin-worker', { ifAvailable: true }, async (lock) => {
      if (ticket !== generation || !eligible()) return
      if (!lock) {
        timer = setTimeout(() => lead(ticket, interval), interval)
        return
      }
      await new Promise((resolve) => {
        releaseLeader = resolve
        consume(ticket, interval)
      })
    }).catch(() => {
      // PostgreSQL still coordinates browsers when Web Locks are unavailable.
      if (ticket === generation && eligible()) consume(ticket, interval)
    })
  }

  async function restart() {
    const ticket = ++generation
    clearTimeout(timer)
    releaseLeader?.()
    releaseLeader = null
    if (!eligible()) return
    try {
      const config = await apiGet('/admin/tools/worker/config')
      if (ticket === generation && eligible() && config.data.enabled) lead(ticket, config.data.idleIntervalMs)
    } catch { /* The Tools view reports configuration and queue state. */ }
  }
  watch(() => [user.value?.id, user.value?.isAdmin, route.meta.adminSection], restart, { immediate: true })
  document.addEventListener('visibilitychange', restart)
  onBeforeUnmount(() => {
    disposed = true
    releaseLeader?.()
    releaseLeader = null
    ++generation
    clearTimeout(timer)
    document.removeEventListener('visibilitychange', restart)
  })
}
