import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { apiGet } from '../lib/api'

export function useDirectoryData(endpoint, locale, page, pageSize, filters = {}) {
  const items = ref([])
  const total = ref(0)
  const loading = ref(true)
  const error = ref('')
  const filterEntries = Object.entries(filters)
  let requestVersion = 0

  async function load() {
    const currentRequestVersion = ++requestVersion
    const offset = (page.value - 1) * pageSize.value
    const params = new URLSearchParams({
      limit: String(pageSize.value),
      offset: String(offset),
    })

    filterEntries.forEach(([key, value]) => {
      const filterValue = String(value.value || '').trim()
      if (filterValue) {
        params.set(key, filterValue)
      }
    })

    loading.value = true
    error.value = ''
    items.value = []
    total.value = 0

    try {
      const response = await apiGet(`${endpoint}?${params}`)
      if (currentRequestVersion === requestVersion) {
        items.value = response.data
        total.value = Number.isInteger(response.meta?.total)
          ? response.meta.total
          : response.data.length
      }
    } catch (cause) {
      if (currentRequestVersion === requestVersion) {
        error.value = cause.message
      }
    } finally {
      if (currentRequestVersion === requestVersion) {
        loading.value = false
      }
    }
  }

  const watchSources = [
    locale,
    page,
    pageSize,
    ...filterEntries.map(([, value]) => value),
  ]
  watch(watchSources, load)
  onMounted(load)
  onBeforeUnmount(() => {
    requestVersion += 1
  })

  return { items, total, loading, error }
}
