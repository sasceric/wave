import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

const settle = () => new Promise(resolve => setImmediate(resolve))

test('collaboration reporting is admin-only and discards stale private results after account switches', async () => {
  const requests = []
  const route = reactive({ query: { kind: 'packages', providerId: '12', providerType: 'creator', label: 'Creator' } })
  const { state, currentUser } = await setupView('../src/views/AdminCollaborationsView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': { apiGet: path => new Promise(resolve => requests.push({ path, resolve })), formatDate: String, formatMoney: String },
  }, {}, { AbortController, setTimeout, clearTimeout })
  assert.equal(requests.length, 0)
  currentUser.value = { id: 1, isAdmin: true }
  await nextTick()
  assert.equal(requests.length, 1)
  assert.match(requests[0].path, /providerId=12/)
  assert.doesNotMatch(requests[0].path, /label=/)
  currentUser.value = { id: 2, isAdmin: false }
  await nextTick()
  requests[0].resolve({ userId: 1, data: [{ id: 1, subject: 'Private' }], summary: { requests: 1, engaged: 1, completed: 0 }, meta: { total: 1, page: 1 } })
  await settle()
  assert.equal(state.rows.value.length, 0)
  assert.equal(state.summary.value.requests, 0)
  assert.equal(state.loading.value, false)
})

test('query changes load the selected source and status, keep failed views empty, and reset paging on filters', async () => {
  const requests = []
  const replacements = []
  const route = reactive({ query: { kind: 'packages', page: '3', providerId: '12', providerType: 'creator', label: 'Creator' } })
  const user = ref({ id: 1, isAdmin: true })
  const { state } = await setupView('../src/views/AdminCollaborationsView.vue', {
    '../composables/useCurrentUser': { currentUser: user },
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async value => replacements.push(value) }) },
    '../lib/api': { apiGet: async path => { requests.push(path); throw new Error('Unavailable') }, formatDate: String, formatMoney: String },
  }, {}, { AbortController, setTimeout, clearTimeout })
  await settle()
  assert.equal(state.error.value, 'Unavailable')
  assert.equal(state.rows.value.length, 0)
  state.filter({ status: 'engaged' })
  assert.equal(replacements[0].query.page, undefined)
  assert.equal(replacements[0].query.providerId, '12')
  state.changeKind('campaigns')
  assert.equal(replacements[1].query.kind, 'campaigns')
  assert.equal(replacements[1].query.status, 'requests')
  route.query = { kind: 'campaigns', clientId: '4', status: 'completed' }
  await nextTick(); await settle()
  assert.match(requests.at(-1), /kind=campaigns/)
  assert.match(requests.at(-1), /clientId=4/)
  assert.match(requests.at(-1), /status=completed/)
})

test('chart components destroy old instances and retain an accessible error fallback', async () => {
  const events = []
  const props = reactive({ type: 'line', data: { labels: ['Day'], datasets: [] }, options: {}, label: 'Chart', height: 230 })
  const { state } = await setupView('../src/components/admin/AdminChart.vue', {
    '../../lib/adminCharts': { createAdminChart: () => { events.push('create'); return { destroy: () => events.push('destroy') } } },
  }, props)
  state.canvas.value = {}
  await state.render()
  await state.render()
  assert.deepEqual(events, ['create', 'destroy', 'create'])
  const failed = await setupView('../src/components/admin/AdminChart.vue', {
    '../../lib/adminCharts': { createAdminChart: () => { throw new Error('Canvas unavailable') } },
  }, props)
  failed.state.canvas.value = {}
  await failed.state.render()
  assert.equal(failed.state.error.value, true)
})

test('accepted engagements show the saved work stage, while confirmed completion takes precedence', async () => {
  const { state } = await setupView('../src/views/AdminCollaborationsView.vue', {
    'vue-router': { useRoute: () => ({ query: {} }), useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': { apiGet: async () => {}, formatDate: String, formatMoney: String },
  }, {}, { AbortController, setTimeout, clearTimeout })
  assert.equal(state.statusStage({ status: 'booked', workStatus: 'in_progress' }), 'in_progress')
  assert.equal(state.statusStage({ status: 'booked', workStatus: 'submitted' }), 'submitted')
  assert.equal(state.statusStage({ status: 'booked', workStatus: 'changes_requested' }), 'changes_requested')
  assert.equal(state.statusStage({ status: 'completed', workStatus: 'in_progress' }), 'completed')
  assert.equal(state.statusStage({ status: 'cancelled', workStatus: 'submitted' }), 'cancelled')
  assert.equal(state.statusStage({ status: 'pending', workStatus: 'in_progress' }), 'pending')
  assert.equal(state.statusLabel({ status: 'booked', workStatus: 'submitted' }), 'work.submitted')
  assert.equal(state.statusLabel({ status: 'completed', workStatus: 'in_progress' }), 'adminCollaborations.statuses.completed')
})

test('the request card is the default filter and sends an explicit non-engaged scope to the server', async () => {
  const requests = []
  const route = reactive({ query: {} })
  const { state } = await setupView('../src/views/AdminCollaborationsView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ id: 1, isAdmin: true }) },
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': { apiGet: async path => { requests.push(path); return { data: [], summary: { requests: 3, engaged: 1, completed: 2 }, meta: { page: 1, total: 3 } } }, formatDate: String, formatMoney: String },
  }, {}, { AbortController, setTimeout, clearTimeout })
  await settle()
  assert.equal(state.selectedStatus.value, 'requests')
  assert.equal(new URLSearchParams(requests[0].split('?')[1]).get('status'), 'requests')
  route.query = { status: 'all' }
  await nextTick(); await settle()
  assert.equal(state.selectedStatus.value, 'all')
  assert.equal(new URLSearchParams(requests.at(-1).split('?')[1]).get('status'), 'all')
})
