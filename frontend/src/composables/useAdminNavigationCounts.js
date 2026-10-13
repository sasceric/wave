import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet } from '../lib/api'

const counts = ref(null)
let ownerId = null
let pending = null
let refreshedAt = 0
let revision = 0
let refreshAgain = false
const handledEvents = new WeakSet()

async function load(user, force = false) {
  const id = user?.isAdmin ? user.id : null
  if (ownerId !== id) {
    ++revision
    ownerId = id
    counts.value = null
    pending = null
    refreshedAt = 0
    refreshAgain = false
  }
  if (!id || !user.emailVerified) return
  if (pending) {
    if (force) refreshAgain = true
    return pending
  }
  if (!force && counts.value && Date.now() - refreshedAt < 15000) return
  const requestRevision = revision
  const request = apiGet('/admin/navigation-counts').then(response => {
    if (requestRevision !== revision || response.userId !== id) return
    counts.value = response.data
    refreshedAt = Date.now()
  }).catch(cause => {
    if (requestRevision === revision && [401, 403].includes(cause.status)) counts.value = null
  }).finally(() => {
    if (pending !== request) return
    pending = null
    if (refreshAgain) {
      refreshAgain = false
      void load(user, true)
    }
  })
  pending = request
  return request
}

export function useAdminNavigationCounts(user) {
  const route = useRoute()
  const refresh = event => {
    if (handledEvents.has(event)) return
    handledEvents.add(event)
    void load(user(), true)
  }
  watch(() => [user()?.id, user()?.isAdmin, user()?.emailVerified, route.fullPath], () => { void load(user()) }, { immediate: true })
  onMounted(() => {
    window.addEventListener('focus', refresh)
    window.addEventListener('wave:admin-counts-changed', refresh)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('focus', refresh)
    window.removeEventListener('wave:admin-counts-changed', refresh)
  })
  return computed(() => user()?.isAdmin && user()?.id === ownerId ? counts.value : null)
}
