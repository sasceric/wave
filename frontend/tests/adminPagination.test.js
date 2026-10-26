import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

test('remote tables display server rows without sorting or slicing the page again', async () => {
  const props = vue.reactive({ columns: [{ key: 'name', sortable: true }], rows: [{ id: 26, name: 'Z' }, { id: 27, name: 'A' }], labels: {}, pageSize: 25, total: 61, currentPage: 2, loading: false })
  const { state, emitted } = await setupView('../src/components/admin/AdminDataTable.vue', {}, props)
  assert.deepEqual(state.visibleRows.value, props.rows)
  assert.equal(state.pageCount.value, 3)
  state.changePage(3)
  assert.equal(emitted.length, 1)
  assert.equal(emitted[0][1].page, 3)
  // Server clamping after deletion must not trigger another request.
  props.currentPage = 1
  props.total = 20
  props.rows = [{ id: 1, name: 'First' }]
  await vue.nextTick()
  assert.equal(state.page.value, 1)
  assert.equal(emitted.length, 1)
  state.sortBy(props.columns[0])
  assert.equal(emitted[1][1].direction, 'desc')
  assert.equal(emitted[1][1].page, 1)
  state.pageSize.value = 50
  await vue.nextTick()
  assert.equal(emitted[2][1].limit, 50)
})

test('remote homepage selectors keep selected labels outside the current search page', async () => {
  const props = vue.reactive({ label: 'Creators', options: [{ value: 30, label: 'New page' }], selectionOptions: [{ value: 1, label: 'Selected earlier' }], modelValue: [1], remote: true })
  const { state, emitted } = await setupView('../src/components/shared/MultiSelect.vue', {}, props)
  assert.equal(state.selectedOptions.value[0].label, 'Selected earlier')
  state.isOpen.value = true
  state.query.value = 'server query'
  await vue.nextTick()
  assert.equal(emitted[0][0], 'search')
  assert.equal(state.filteredOptions.value.length, 1)
  state.toggleOption(30)
  assert.deepEqual(Array.from(emitted[1][1]), [1, 30])
})

test('the shared country multiselect keeps local search and selection limits', async () => {
  const props = vue.reactive({ options: [{ value: 'BA', label: 'Bosna i Hercegovina' }, { value: 'DE', label: 'Njemačka' }], selectionOptions: [], modelValue: ['BA'], maxSelections: 1 })
  const { state, emitted } = await setupView('../src/components/shared/MultiSelect.vue', {}, props)
  state.query.value = 'njemacka'
  assert.equal(state.filteredOptions.value[0].value, 'DE')
  state.toggleOption('DE')
  assert.equal(emitted.length, 0)
  state.toggleOption('BA')
  assert.deepEqual(Array.from(emitted[0][1]), [])
})

test('remote multiselect scrolling loads one next batch and stops when exhausted', async () => {
  const props = vue.reactive({ options: [], selectionOptions: [], modelValue: [], remote: true, hasMore: true, loading: false })
  const { state, emitted } = await setupView('../src/components/shared/MultiSelect.vue', {}, props)
  const nearBottom = { currentTarget: { scrollHeight: 1000, clientHeight: 200, scrollTop: 750 } }
  state.onOptionsScroll({ currentTarget: { scrollHeight: 1000, clientHeight: 200, scrollTop: 0 } })
  assert.equal(emitted.length, 0)
  state.onOptionsScroll(nearBottom)
  state.onOptionsScroll(nearBottom)
  assert.equal(emitted.length, 1)
  assert.equal(emitted[0][0], 'load-more')
  props.loading = true
  await vue.nextTick()
  props.loading = false
  await vue.nextTick()
  state.onOptionsScroll(nearBottom)
  assert.equal(emitted.length, 2)
  props.hasMore = false
  state.onOptionsScroll(nearBottom)
  assert.equal(emitted.length, 2)
})

test('required profile fields emit replacements rather than modifying parent props', async () => {
  const props = { profile: { displayName: 'Before', countryCode: 'BA' }, user: {}, phoneCountry: 'BA' }
  const { state, emitted } = await setupView('../src/components/account/RequiredProfileModal.vue', {}, props)
  state.profile.value.displayName = 'After'
  assert.equal(props.profile.displayName, 'Before')
  assert.equal(emitted[0][0], 'update:profile')
  assert.equal(emitted[0][1].displayName, 'After')
  state.updateCountry('HR')
  assert.equal(props.profile.countryCode, 'BA')
  assert.equal(emitted[1][1].countryCode, 'HR')
  assert.deepEqual(Array.from(emitted[2]), ['update:phoneCountry', 'HR'])
})

test('admin page responses cannot overwrite a more recent sort or page request', async () => {
  const requests = []
  const { state } = await setupView('../src/views/AdminDashboardView.vue', {
    'vue-router': { useRoute: () => ({ meta: { adminSection: 'creators' } }) },
    '../lib/api': { formatMoney: (value) => String(value), apiGet: (path) => new Promise((resolve) => requests.push({ path, resolve })), apiRequest: async () => {} },
  }, {}, { setTimeout, clearTimeout })
  const first = state.loadList({ page: 2, limit: 25, sort: 'displayName', direction: 'asc' })
  const second = state.loadList({ page: 3, limit: 25, sort: 'displayName', direction: 'desc' })
  requests[1].resolve({ data: [{ id: 3 }], meta: { page: 3, total: 100 } })
  await second
  requests[0].resolve({ data: [{ id: 2 }], meta: { page: 2, total: 100 } })
  await first
  assert.equal(state.listPage.value, 3)
  assert.equal(state.listRows.value[0].id, 3)
  assert.equal(state.listLoading.value, false)
  assert.match(requests[1].path, /page=3&limit=25&sort=displayName&direction=desc/)
})

test('homepage search and candidate paging preserve existing featured IDs when saving', async () => {
  let saved
  const { state } = await setupView('../src/views/AdminDashboardView.vue', {
    'vue-router': { useRoute: () => ({ meta: { adminSection: 'homepage' } }) },
    '../lib/api': {
      formatMoney: (value) => String(value),
      apiGet: async (path) => {
        if (path.startsWith('/admin/dashboard')) return { data: { metrics: {}, creatorMode: 'featured', creators: [{ id: 100, displayName: 'Featured earlier', featured: true }], companies: [], campaigns: [] } }
        const params = new URLSearchParams(path.split('?')[1])
        return { data: [{ id: params.has('q') && params.get('q') ? 50 : 1, displayName: 'Candidate', name: 'Company', title: 'Campaign', company: { name: 'Company' } }], meta: { page: 1, total: 1 } }
      },
      apiRequest: async (path, options) => { saved = options.body },
    },
  }, {}, { setTimeout, clearTimeout })
  await state.loadDashboard()
  assert.deepEqual(Array.from(state.featuredCreatorIds.value), [100])
  state.candidates.creators.q = 'Search another creator'
  await state.loadCandidates('creators', true)
  assert.equal(state.candidates.creators.rows[0].id, 50)
  assert.ok(state.dashboard.value.creators.some(({ id }) => id === 100))
  state.setFeaturedExpiry('creators', 100, '2026-11-30')
  await state.saveCuration()
  assert.equal(saved.featuredExpirations.creators[100], '2026-11-30')
  assert.equal(state.curationDirty.value, false)
  assert.deepEqual(Array.from(saved.featuredCreatorIds), [100])
})


test('table totals count the full matching dataset, including empty results, rather than the current page', async () => {
  const props = vue.reactive({ columns: [], rows: [{ id: 26 }], labels: {}, pageSize: 25, total: 61, currentPage: 2 })
  const { state } = await setupView('../src/components/admin/AdminDataTable.vue', {}, props)
  assert.equal(state.totalResults.value, 61)
  props.total = 0
  props.rows = []
  await vue.nextTick()
  assert.equal(state.totalResults.value, 0)
  props.total = null
  props.rows = Array.from({ length: 30 }, (_, id) => ({ id }))
  await vue.nextTick()
  assert.equal(state.totalResults.value, 30)
  assert.equal(state.visibleRows.value.length, 25)
})

test('read-only tables distinguish known totals from loaded pages when the API has no total', async () => {
  const props = vue.reactive({ total: 61, rows: 25, page: 2, hasMore: true })
  const { state } = await setupView('../src/components/admin/AdminTableResults.vue', {}, props)
  assert.equal(state.count.value, 61)
  assert.equal(state.knownTotal.value, true)
  props.total = null
  await vue.nextTick()
  assert.equal(state.count.value, 25)
  assert.equal(state.knownTotal.value, false)
  props.page = 1
  props.hasMore = false
  await vue.nextTick()
  assert.equal(state.knownTotal.value, true)
})
