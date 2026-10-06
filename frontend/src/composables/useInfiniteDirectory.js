import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet } from '../lib/api'

export function useInfiniteDirectory(endpoint, locale, filters = {}, batchSize = 30) {
  const { t } = useI18n()
  const items = ref([])
  const total = ref(0)
  const loading = ref(true)
  const error = ref('')
  const hasMore = ref(true)
  const filterEntries = Object.entries(filters)
  let nextOffset = 0
  let requestVersion = 0
  let disposed = false

  async function loadMore() {
    if (disposed || loading.value || !hasMore.value) return
    const version = requestVersion
    const params = new URLSearchParams({ limit: String(batchSize), offset: String(nextOffset), view: 'card' })
    filterEntries.forEach(([key, value]) => {
      const filterValue = String(value.value || '').trim()
      if (filterValue) params.set(key, filterValue)
    })
    loading.value = true
    error.value = ''

    try {
      const response = await apiGet(`${endpoint}?${params}`, { locale: locale.value })
      if (disposed || version !== requestVersion) return
      if (!Array.isArray(response.data)) throw new Error(t('api.invalidResponse'))

      // Advance by the server batch, even if a changing directory repeats a card.
      nextOffset += response.data.length
      const seen = new Set(items.value.map((item) => item.id))
      const additions = response.data.filter((item) => {
        if (seen.has(item.id)) return false
        seen.add(item.id)
        return true
      })
      items.value = [...items.value, ...additions]
      const hasTotal = Number.isInteger(response.meta?.total) && response.meta.total >= 0
      total.value = hasTotal ? response.meta.total : nextOffset
      hasMore.value = response.data.length > 0 && (hasTotal
        ? nextOffset < total.value
        : response.data.length === batchSize)
    } catch (cause) {
      if (!disposed && version === requestVersion) error.value = cause.message
    } finally {
      if (!disposed && version === requestVersion) loading.value = false
    }
  }

  function reset() {
    requestVersion += 1
    nextOffset = 0
    items.value = []
    total.value = 0
    error.value = ''
    hasMore.value = true
    loading.value = false
    return loadMore()
  }

  // Invalidate old batches immediately when a filter or language changes.
  watch([locale, ...filterEntries.map(([, value]) => value)], reset, { flush: 'sync' })
  onMounted(reset)
  onBeforeUnmount(() => {
    disposed = true
    requestVersion += 1
  })

  return { items, total, loading, error, hasMore, loadMore, reset }
}
