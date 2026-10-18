import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

const settle = () => new Promise(resolve => setImmediate(resolve))

test('admin visibility updates the selected row only after success and cannot publish an ineligible account', async () => {
  const requests = []
  const route = reactive({ meta: { adminSection: 'companies' }, query: {} })
  const { state } = await setupView('../src/views/AdminDashboardView.vue', {
    'vue-router': { useRoute: () => route },
    '../lib/api': { apiGet: async () => ({}), formatMoney: String, apiRequest: (path, options) => new Promise((resolve, reject) => requests.push({ path, options, resolve, reject })) },
  }, {}, { setTimeout, clearTimeout })
  state.listRows.value = [{ id: 12, name: 'Agency', publicVisible: true, canChangeVisibility: true, canShowProfile: true, completion: { services: 2 } }]
  const row = state.listRows.value[0]
  const hiding = state.changeVisibility(row)
  assert.equal(requests[0].path, '/admin/catalog/companies/12/visibility')
  assert.equal(requests[0].options.body.hideMyAccount, true)
  assert.equal(row.publicVisible, true)
  await state.changeVisibility(row)
  assert.equal(requests.length, 1)
  requests[0].resolve({ data: { publicVisible: false, hideMyAccount: true } })
  await hiding
  assert.equal(row.publicVisible, false)
  assert.equal(row.name, 'Agency')
  assert.equal(row.completion.services, 2)
  row.canShowProfile = false
  await state.changeVisibility(row)
  assert.equal(requests.length, 1)
  row.canShowProfile = true
  const showing = state.changeVisibility(row)
  assert.equal(requests[1].options.body.hideMyAccount, false)
  requests[1].reject(new Error('Not allowed'))
  await showing
  assert.equal(row.publicVisible, false)
  assert.equal(state.error.value, 'Not allowed')
})

test('private admin review ignores old responses after switching profiles, closing or signing out', async () => {
  const pending = []
  const user = ref({ id: 1, isAdmin: true })
  const props = reactive({ open: true, kind: 'registrations', id: 4 })
  const { state, emitted } = await setupView('../src/components/admin/AdminProfileReview.vue', {
    '../../composables/useCurrentUser': { currentUser: user },
    '../../lib/api': { apiGet: path => new Promise(resolve => pending.push({ path, resolve })), formatDate: String },
  }, props)
  assert.equal(pending[0].path, '/admin/catalog/registrations/4/profile')
  props.id = 5
  await nextTick()
  pending[0].resolve({ data: { email: 'old@example.test' } })
  await settle()
  assert.equal(state.data.value, null)
  pending[1].resolve({ data: { email: 'current@example.test' } })
  await settle()
  assert.equal(state.data.value.email, 'current@example.test')
  props.open = false
  await nextTick()
  assert.equal(state.data.value, null)
  props.open = true
  await nextTick()
  user.value = null
  await nextTick()
  pending[2].resolve({ data: { email: 'private@example.test' } })
  await settle()
  assert.equal(state.data.value, null)
  assert.equal(emitted.at(-1)[0], 'update:open')
  assert.equal(emitted.at(-1)[1], false)
  assert.equal(state.safeLink('javascript:alert(1)'), null)
  assert.equal(state.safeLink('https://example.test/a'), 'https://example.test/a')
})
