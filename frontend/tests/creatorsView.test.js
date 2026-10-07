import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'
const modules = {
  '../composables/useInfiniteDirectory': { useInfiniteDirectory: () => ({ items: ref([]), total: ref(0), loading: ref(false), error: ref(''), hasMore: ref(false), loadMore: async () => {} }) },
  '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref(''), refresh: async () => {} }) },
  '../lib/marketplace': { SOCIAL_PLATFORMS: ['TikTok', 'Instagram', 'YouTube'] },
}
test('creator selections use stable values and clear without changing sorting', async () => {
  const { state } = await setupView('../src/views/CreatorsView.vue', modules)
  state.selectedCategories.value = ['Travel', 'Food']
  state.selectedCountries.value = ['BA', 'HR']
  state.selectedPlatforms.value = ['TikTok', 'Instagram']
  state.city.value = 'Zenica'
  state.audience.value = 'medium'
  assert.equal(state.activeFilterCount.value, 8)
  assert.deepEqual(JSON.parse(state.platforms.value), ['TikTok', 'Instagram'])
  assert.deepEqual(JSON.parse(state.categories.value), ['Travel', 'Food'])
  state.clearFilters()
  assert.equal(state.activeFilterCount.value, 0)
  assert.equal(state.countries.value, '')
  assert.equal(state.sort.value, 'newest')
})
test('creator platform links retain their filter and reject unsupported platforms', async () => {
  const { state } = await setupView('../src/views/CreatorsView.vue', modules)
  assert.deepEqual([...state.normalizePlatform('instagram')], ['Instagram'])
  assert.equal(state.normalizePlatform('unsupported').length, 0)
})
