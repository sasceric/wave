import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

const directoryModule = {
  '../composables/useInfiniteDirectory': {
    useInfiniteDirectory: () => ({ items: ref([]), total: ref(0), loading: ref(false), error: ref(''), hasMore: ref(false), loadMore: async () => {} }),
  },
}

test('company filters use stable industry values and clear all selected fields', async () => {
  const { state } = await setupView('../src/views/CompaniesView.vue', directoryModule)
  state.selectedIndustries.value = ['Travel', 'Food & drinks']
  assert.deepEqual(JSON.parse(state.industries.value), ['Travel', 'Food & drinks'])
  state.city.value = 'Zenica'
  state.selectedCountries.value = ['BA', 'HR']
  assert.deepEqual(JSON.parse(state.countries.value), ['BA', 'HR'])
  state.verified.value = true
  assert.equal(state.activeFilterCount.value, 6)
  state.clearFilters()
  assert.equal(state.industries.value, '')
  assert.equal(state.countries.value, '')
  assert.equal(state.activeFilterCount.value, 0)
})

test('company facets ignore responses from a previous locale request', async () => {
  const pending = []
  const { state } = await setupView('../src/views/CompaniesView.vue', {
    ...directoryModule,
    '../lib/api': { apiGet: () => new Promise((resolve) => pending.push(resolve)) },
  })
  const first = state.loadFacets()
  const second = state.loadFacets()
  pending[1]({ data: { industries: [{ value: 'Travel', label: 'Putovanja' }], countries: [] } })
  await second
  pending[0]({ data: { industries: [{ value: 'Travel', label: 'Travel' }], countries: [] } })
  await first
  assert.equal(state.facets.value.industries[0].label, 'Putovanja')
})

