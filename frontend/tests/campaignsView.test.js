import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

function modules(capture = () => {}) {
  return {
    '../composables/useInfiniteDirectory': { useInfiniteDirectory: (path, locale, filters) => {
      capture(path, filters)
      return { items: ref([]), total: ref(0), loading: ref(false), error: ref(''), hasMore: ref(false), loadMore: async () => {} }
    } },
    '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref(''), refresh: async () => {} }) },
    '../lib/marketplace': { SOCIAL_PLATFORMS: ['TikTok', 'Instagram', 'YouTube'], CURRENCIES: ['BAM', 'EUR', 'RSD'] },
  }
}

test('campaign filters reach the directory API using campaign fields and stable catalog values', async () => {
  let filters
  const { state } = await setupView('../src/views/CampaignsView.vue', modules((path, values) => { assert.equal(path, '/campaigns'); filters = values }))
  state.selectedCategories.value = ['Travel', 'Food']
  state.selectedChannels.value = ['Instagram', 'TikTok']
  state.location.value = 'Zenica'
  state.currency.value = 'BAM'
  state.budgetMin.value = 0
  state.budgetMax.value = '500'
  assert.deepEqual(JSON.parse(filters.categories.value), ['Travel', 'Food'])
  assert.deepEqual(JSON.parse(filters.channels.value), ['Instagram', 'TikTok'])
  assert.equal(filters.location.value, 'Zenica')
  assert.equal(filters.currency.value, 'BAM')
  assert.equal(filters.budgetMin.value, '0')
  state.budgetMax.value = 0
  assert.equal(filters.budgetMax.value, '0')
  assert.equal(state.activeFilterCount.value, 8)
  assert.ok(!('city' in filters) && !('countries' in filters) && !('audience' in filters))
  state.clearFilters()
  assert.equal(state.activeFilterCount.value, 0)
  assert.equal(filters.categories.value, '')
  assert.equal(filters.channels.value, '')
  assert.equal(state.sort.value, 'recommended')
})

test('clearing campaign currency clears monetary bounds to prevent comparing different units', async () => {
  const { state } = await setupView('../src/views/CampaignsView.vue', modules())
  state.currency.value = 'EUR'
  state.budgetMin.value = '100'
  state.budgetMax.value = '500'
  state.currency.value = ''
  assert.equal(state.budgetMin.value, '')
  assert.equal(state.budgetMax.value, '')
  assert.equal(state.queryString(['bad', 'query']), '')
})
