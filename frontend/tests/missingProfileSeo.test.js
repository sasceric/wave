import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
const { ref } = vue
import { setupView } from './setupView.js'

for (const [view, load] of [['CreatorProfileView', 'loadCreator'], ['CompanyProfileView', 'loadCompany'], ['CampaignDetailView', 'loadCampaign']]) {
  test(`${view} clears public metadata after a missing record, without treating server errors as not found`, async () => {
    const metadata = []
    let status = 404
    const { state } = await setupView(`../src/views/${view}.vue`, {
      'vue-router': { useRoute: () => ({ params: { slug: 'missing' }, meta: { routeName: 'profile' } }) },
      '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
      '../lib/api': { apiGet: async () => { throw Object.assign(new Error('Unavailable'), { status }) }, apiRequest: async () => {}, formatDate: value => value, formatFollowers: value => value, formatMoney: value => value },
      '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: value => metadata.push(value) },
    })
    await state[load]()
    assert.equal(metadata.length, 1)
    assert.equal(metadata[0].route.meta.routeName, 'not-found')
    assert.equal(metadata[0].title, 'seo.notFoundTitle')
    assert.equal(metadata[0].noindex, true)
    status = 500
    await state[load]()
    assert.equal(metadata.length, 1)
  })

  test(`${view} cannot replace the next page's metadata with a late 404 after unmount`, async () => {
    const metadata = []
    const unmount = []
    let rejectRequest
    const pending = new Promise((_resolve, reject) => { rejectRequest = reject })
    const { state } = await setupView(`../src/views/${view}.vue`, {
      vue: { ...vue, onMounted: () => {}, onBeforeUnmount: callback => unmount.push(callback) },
      'vue-router': { useRoute: () => ({ params: { slug: 'missing' }, meta: { routeName: 'profile' } }) },
      '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
      '../lib/api': { apiGet: () => pending, apiRequest: async () => {}, formatDate: value => value, formatFollowers: value => value, formatMoney: value => value },
      '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: value => metadata.push(value) },
    })
    const loading = state[load]()
    unmount.forEach(callback => callback())
    rejectRequest(Object.assign(new Error('Missing previous profile'), { status: 404 }))
    await loading
    assert.deepEqual(metadata, [])
    assert.equal(state.error.value, '')
  })
}
