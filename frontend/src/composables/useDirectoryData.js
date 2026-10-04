import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { apiGet } from '../lib/api'

export function useDirectoryData(endpoint, category, locale) {
  const items = ref([])
  const loading = ref(false)
  const error = ref('')
  let requestVersion = 0

  async function load() {
    const currentRequestVersion = ++requestVersion
    loading.value = true
    error.value = ''

    const params = new URLSearchParams({ limit: '50' })
    if (category.value) {
      params.set('category', category.value)
    }

    try {
      const response = await apiGet(`${endpoint}?${params}`)
      if (currentRequestVersion === requestVersion) {
        items.value = response.data
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

  watch([category, locale], load)
  onMounted(load)
  onBeforeUnmount(() => {
    requestVersion += 1
  })

  return { items, loading, error }
}
