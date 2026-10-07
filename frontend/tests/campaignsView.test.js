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
  state.city.value = 'Zenica'
  state.selectedCountries.value = ['BA', 'HR']
  state.currency.value = 'BAM'
  state.budgetMin.value = 0
  state.budgetMax.value = '500'
  assert.deepEqual(JSON.parse(filters.categories.value), ['Travel', 'Food'])
  assert.deepEqual(JSON.parse(filters.channels.value), ['Instagram', 'TikTok'])
  assert.equal(filters.city.value, 'Zenica')
  assert.deepEqual(JSON.parse(filters.countries.value), ['BA', 'HR'])
  assert.equal(filters.currency.value, 'BAM')
  assert.equal(filters.budgetMin.value, '0')
  state.budgetMax.value = 0
  assert.equal(filters.budgetMax.value, '0')
  assert.equal(state.activeFilterCount.value, 10)
  assert.ok(!('location' in filters) && !('audience' in filters))
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

test('selecting currency enables the default budget range and clearing resets it', async () => {
  const { state } = await setupView('../src/views/CampaignsView.vue', modules())
  state.updateBudget([100, 20000])
  assert.equal(state.budgetMin.value, '')
  state.currency.value = 'BAM'
  assert.equal(state.budgetMin.value, 0)
  assert.equal(state.budgetMax.value, 10000)
  state.updateBudget([500, 30000])
  assert.equal(state.budgetMin.value, 500)
  assert.equal(state.budgetMax.value, 30000)
  state.clearFilters()
  assert.equal(state.currency.value, '')
  assert.deepEqual(Array.from(state.budgetRange.value), [0, 10000])
})
