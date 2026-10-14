import { onBeforeUnmount, watch } from 'vue'
import { apiRequest } from '../lib/api'
export function useCreatorProfileVisit(creator) {
  let cancel = () => {}
  watch(() => creator.value?.slug, slug => {
    cancel()
    if (!slug) return
    let active = true
    const record = () => {
      if (active && document.visibilityState === 'visible') apiRequest(`/creators/${encodeURIComponent(slug)}/view`, { method:'POST' }).catch(() => {})
    }
    if (typeof window.requestIdleCallback === 'function') {
      const task = window.requestIdleCallback(record, { timeout:5000 })
      cancel = () => { active = false; window.cancelIdleCallback(task) }
    } else {
      const task = window.setTimeout(record, 3000)
      cancel = () => { active = false; window.clearTimeout(task) }
    }
  })
  onBeforeUnmount(() => cancel())
}
