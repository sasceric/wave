import { onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue'
import { apiGet } from '../lib/api'

export function useMarketplaceCatalog(locale, { includeFaqs = false, includeIndustries = false, enabled = true } = {}) {
  const industries = ref([])
  const categories = ref([])
  const faqs = ref([])
  const loading = ref(false)
  const error = ref('')
  let requestVersion = 0

  async function load() {
    const currentRequestVersion = ++requestVersion
    error.value = ''
    if (!toValue(enabled)) {
      loading.value = false
      return
    }
    loading.value = true

    try {
      const requests = [apiGet('/marketplace/categories')]
      if (includeFaqs) {
        requests.push(apiGet('/marketplace/creator-faqs'))
      }
      const responses = await Promise.all(requests)
      const [categoryResponse] = responses
      const faqResponse = includeFaqs ? responses[1] : null
      if (currentRequestVersion === requestVersion) {
        industries.value = includeIndustries ? categoryResponse.data : []
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

  watch([locale, () => toValue(enabled)], load)
  onMounted(load)
  onBeforeUnmount(() => {
    requestVersion += 1
  })

  return { industries, categories, faqs, loading, error, refresh: load }
}
