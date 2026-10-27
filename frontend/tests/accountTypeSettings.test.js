import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function settings(user, apiRequest = async (_path, options) => ({ data: { ...options.body, id: 42, status: 'pending' } })) {
  const currentUser = vue.ref(user)
  const calls = []
  const view = await setupView('../src/components/account/AccountTypeSettings.vue', {
    '../../composables/useCurrentUser': { currentUser, setCurrentUser: value => { currentUser.value = value } },
    '../../lib/api': { apiGet: async () => ({ data: [] }), apiRequest: async (...args) => { calls.push(args); return apiRequest(...args) }, formatDate: String },
  })
  await vue.nextTick()
  return { ...view, currentUser, calls }
}

test('creators can request brand or agency; companies request creator without changing their own role', async () => {
  const creator = await settings({ id: 1, accountType: 'creator', profile: { displayName: 'Creator' } })
  assert.deepEqual(Array.from(creator.state.targetOptions.value, option => option.value), ['brand', 'agency'])
  creator.state.openRequest()
  creator.state.targetType.value = 'agency'
  await creator.state.submit()
  assert.equal(creator.calls[0][0], '/me/account-type-requests')
  assert.equal(creator.calls[0][1].body.targetType, 'agency')
  assert.equal(creator.state.pending.value.status, 'pending')
  assert.equal(creator.currentUser.value.accountType, 'creator')
  assert.equal(creator.state.requestOpen.value, false)
  const company = await settings({ id: 2, accountType: 'company', profile: { companyType: 'agency', name: 'Agency' } })
  assert.deepEqual(Array.from(company.state.targetOptions.value, option => option.value), ['creator'])
})

test('private company details are editable and a failed request preserves the draft', async () => {
  const user = { id: 2, accountType: 'company', profile: { companyType: 'brand', businessDetails: { registrationId: 'ID-1', ownerFullName: 'Private Name' } } }
  const view = await settings(user, async (_path, options) => ({ data: { ...user, profile: { ...user.profile, businessDetails: options.body } } }))
  view.state.openBusiness()
  assert.equal(view.state.details.value.ownerFullName, 'Private Name')
  view.state.details.value.vatId = 'VAT-2'
  await view.state.saveBusiness()
  assert.equal(view.calls[0][0], '/me/company-details')
  assert.equal(view.currentUser.value.profile.businessDetails.vatId, 'VAT-2')
  const failed = await settings(user, async () => { throw new Error('Server rejected') })
  failed.state.openRequest()
  failed.state.message.value = 'Keep this draft'
  await failed.state.submit()
  assert.equal(failed.state.requestOpen.value, true)
  assert.equal(failed.state.message.value, 'Keep this draft')
  assert.equal(failed.state.busy.value, false)
})

test('admins approve requests through the guarded review endpoint', async () => {
  const calls = []
  const { state } = await setupView('../src/views/AdminAccountTypesView.vue', {
    '../composables/useCurrentUser': { currentUser: vue.ref({ isAdmin: true }) },
    '../lib/api': { apiGet: async () => ({ data: [], meta: { total: 0 } }), apiRequest: async (...args) => { calls.push(args); return { data: {} } }, formatDate: String },
  })
  state.review({ id: 12, status: 'pending', response: '' })
  await state.decide('approved')
  assert.equal(calls[0][0], '/admin/account-type-requests/12')
  assert.equal(calls[0][1].body.decision, 'approved')
  assert.equal(state.selected.value, null)
})
