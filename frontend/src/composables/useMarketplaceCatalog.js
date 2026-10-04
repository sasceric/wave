import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { apiGet } from '../lib/api'

export function useMarketplaceCatalog(locale, { includeFaqs = false } = {}) {
  const categories = ref([])
  const faqs = ref([])
  const loading = ref(false)
  const error = ref('')
  let requestVersion = 0

  async function load() {
    const currentRequestVersion = ++requestVersion
    loading.value = true
    error.value = ''

    try {
      const requests = [apiGet('/marketplace/categories')]
      if (includeFaqs) {
        requests.push(apiGet('/marketplace/creator-faqs'))
      }
      const [categoryResponse, faqResponse] = await Promise.all(requests)
      if (currentRequestVersion === requestVersion) {
        categories.value = categoryResponse.data
        faqs.value = faqResponse?.data || []
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

  watch(locale, load)
  onMounted(load)
  onBeforeUnmount(() => {
    requestVersion += 1
  })

  return { categories, faqs, loading, error, refresh: load }
}
