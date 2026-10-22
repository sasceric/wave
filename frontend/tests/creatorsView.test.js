import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'
import { PRESENTATION_PLATFORMS } from '../src/lib/creatorPresentation.js'
const modules = {
  '../composables/useInfiniteDirectory': { useInfiniteDirectory: () => ({ items: ref([]), total: ref(0), loading: ref(false), error: ref(''), hasMore: ref(false), loadMore: async () => {} }) },
  '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref(''), refresh: async () => {} }) },
  '../lib/creatorPresentation': { PRESENTATION_PLATFORMS },
}
test('creator selections use stable values and clear without changing sorting', async () => {
  const { state } = await setupView('../src/views/CreatorsView.vue', modules)
  assert.equal(state.sort.value, 'recommended')
  state.sort.value = 'followers'
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
  assert.equal(state.sort.value, 'followers')
})
test('creator platform links retain their filter and reject unsupported platforms', async () => {
  const { state } = await setupView('../src/views/CreatorsView.vue', modules)
  assert.deepEqual([...state.normalizePlatform('instagram')], ['Instagram'])
  assert.deepEqual([...state.normalizePlatform('twitch')], ['Twitch'])
  assert.deepEqual([...state.normalizePlatform('spotify')], ['Spotify'])
  assert.equal(state.normalizePlatform('unsupported').length, 0)
})

test('creator age range filters use inclusive bounds, reset to 18–70, and count as one filter', async () => {
  let filters
  const { state } = await setupView('../src/views/CreatorsView.vue', {
    ...modules,
    '../composables/useInfiniteDirectory': { useInfiniteDirectory: (_path, _locale, values) => {
      filters = values
      return modules['../composables/useInfiniteDirectory'].useInfiniteDirectory()
    } },
  })
  assert.deepEqual([...state.ageRange.value], [18, 70])
  assert.equal(filters.ageMin.value, '')
  assert.equal(filters.ageMax.value, '')
  state.ageRange.value = [25, 40]
  assert.equal(filters.ageMin.value, '25')
  assert.equal(filters.ageMax.value, '40')
  assert.equal(state.activeFilterCount.value, 1)
  state.ageRange.value = [18, 18]
  assert.equal(filters.ageMin.value, '18')
  assert.equal(filters.ageMax.value, '18')
  state.clearFilters()
  assert.deepEqual([...state.ageRange.value], [18, 70])
  assert.equal(filters.ageMin.value, '')
  assert.equal(filters.ageMax.value, '')
  assert.equal(state.activeFilterCount.value, 0)
})
