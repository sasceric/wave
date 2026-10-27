import assert from 'node:assert/strict'
import test from 'node:test'
import { reactive } from 'vue'
import { setupView } from './setupView.js'

async function dashboard({ fail = false } = {}) {
  const writes = []
  let leave
  let update
  const view = await setupView('../src/views/AdminDashboardView.vue', {
    'vue-router': {
      useRoute: () => ({ meta: { adminSection: 'homepage' }, query: {} }),
      onBeforeRouteLeave: callback => { leave = callback },
      onBeforeRouteUpdate: callback => { update = callback },
    },
    '../lib/api': {
      formatMoney: String,
      apiGet: async path => path.startsWith('/admin/dashboard')
        ? { data: { metrics: {}, creatorMode: 'latest', creators: [{ id: 1, displayName: 'Creator', featured: true, featuredUntil: '2026-11-01' }], companies: [{ id: 2, name: 'Brand', featured: true }], campaigns: [] } }
        : { data: [], meta: { page: 1, total: 0 } },
      apiRequest: async (path, options) => { writes.push({ path, ...options }); if (fail) throw new Error('Save failed') },
    },
  }, {}, { clearTimeout })
  await view.state.loadDashboard()
  return { ...view, writes, leave, update }
}

test('homepage saves only selected expiry dates and marks a successful snapshot saved', async () => {
  const { state, writes } = await dashboard()
  assert.equal(state.curationDirty.value, false)
  state.setFeaturedExpiry('companies', 2, '2026-12-01')
  state.setFeaturedExpiry('creators', 99, '2026-12-02')
  assert.equal(state.curationDirty.value, true)
  await state.saveCuration()
  assert.equal(writes[0].path, '/admin/homepage')
  assert.equal(writes[0].body.featuredExpirations.creators[1], '2026-11-01')
  assert.equal(writes[0].body.featuredExpirations.creators[99], undefined)
  assert.equal(writes[0].body.featuredExpirations.companies[2], '2026-12-01')
  assert.equal(state.curationDirty.value, false)
  state.selectFeatured('creators', [])
  assert.deepEqual(Object.keys(state.curationBody.value.featuredExpirations.creators), [])
})

test('failed saves keep the homepage draft and navigation warnings until the user discards changes', async () => {
  const { state, writes, leave, update, windowTarget } = await dashboard({ fail: true })
  state.setFeaturedExpiry('creators', 1, '')
  await state.saveCuration()
  assert.equal(state.curationDirty.value, true)
  assert.equal(state.featuredExpirations.value.creators[1], '')
  assert.equal(state.error.value, 'Save failed')
  assert.equal(writes.length, 1)
  windowTarget.confirm = () => false
  assert.equal(leave(), false)
  assert.equal(update(), false)
  windowTarget.confirm = () => true
  assert.equal(leave(), true)
  let prevented = false
  const event = { preventDefault: () => { prevented = true } }
  state.warnCurationUnload(event)
  assert.equal(prevented, true)
  assert.equal(event.returnValue, '')
})

test('homepage selectors expose images and retain selected rows outside the current search page', async () => {
  const props = reactive({
    mode: 'featured', positions: { creators: {}, companies: {}, campaigns: {} }, selections: { creators: [1], companies: [], campaigns: [] }, expirations: {},
    catalog: { creators: [{ id: 1, displayName: 'Selected', avatarUrl: '/avatar.jpg' }], companies: [{ id: 3, name: 'Brand', logoUrl: '/logo.jpg' }], campaigns: [{ id: 4, title: 'Campaign', coverImageUrl: '/cover.jpg' }] },
    candidates: { creators: { rows: [{ id: 2, displayName: 'Candidate' }], total: 1, loading: false }, companies: { rows: [] }, campaigns: { rows: [] } },
  })
  const { state } = await setupView('../src/components/admin/AdminHomepageCuration.vue', {}, props)
  assert.equal(state.selectedRows.value[0].displayName, 'Selected')
  assert.equal(state.selectionOptions.value[0].image, '/avatar.jpg')
  state.active.value = 'companies'
  assert.equal(state.selectionOptions.value[0].image, '/logo.jpg')
  state.active.value = 'campaigns'
  assert.equal(state.selectionOptions.value[0].image, '/cover.jpg')
})


test('featured positions are optional, scoped to selected rows and retained after a failed save', async () => {
  const { state, writes } = await dashboard({ fail: true })
  state.setFeaturedPosition('creators', 1, 3)
  state.setFeaturedPosition('creators', 99, 1)
  state.setFeaturedPosition('companies', 2, '')
  assert.equal(state.curationDirty.value, true)
  await state.saveCuration()
  assert.equal(writes[0].body.creatorMode, 'featured')
  assert.equal(writes[0].body.featuredPositions.creators[1], 3)
  assert.equal(writes[0].body.featuredPositions.creators[99], undefined)
  assert.equal(writes[0].body.featuredPositions.companies[2], null)
  assert.equal(state.featuredPositions.value.creators[1], 3)
  assert.equal(state.curationDirty.value, true)
})


test('companies homepage switch defaults off and saves independently of featuring', async () => {
  const { state, writes } = await dashboard()
  assert.equal(state.companiesSliderEnabled.value, false)
  assert.equal(state.curationDirty.value, false)
  state.companiesSliderEnabled.value = true
  assert.equal(state.curationDirty.value, true)
  await state.saveCuration()
  assert.equal(writes[0].body.companiesSliderEnabled, true)
  assert.deepEqual(Array.from(writes[0].body.featuredCompanyIds), [2])
  state.companiesSliderEnabled.value = false
  await state.saveCuration()
  assert.equal(writes[1].body.companiesSliderEnabled, false)
  assert.deepEqual(Array.from(writes[1].body.featuredCompanyIds), [2])
})
