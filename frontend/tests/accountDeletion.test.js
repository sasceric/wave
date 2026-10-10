import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function deletionState(request) {
  const user = vue.ref({ id: 9 })
  const navigation = []
  const result = await setupView('../src/components/account/AccountDeletionAction.vue', {
    '../../lib/api': { apiRequest: request },
    '../../composables/useCurrentUser': { setCurrentUser: value => { user.value = value } },
    '../../routePaths': { localizedPath: () => '/racun' },
    'vue-router': { useRouter: () => ({ replace: async target => { navigation.push(target) } }) },
  })
  return { ...result, user, navigation }
}

test('account deletion requires opening confirmation and prevents duplicate requests during deletion', async () => {
  const calls = []
  let resolve
  const { state, user, navigation, emitted } = await deletionState((path, options) => {
    calls.push([path, options])
    return new Promise(done => { resolve = done })
  })
  assert.equal(state.open.value, false)
  state.showConfirmation()
  assert.equal(state.open.value, true)
  assert.equal(calls.length, 0)
  assert.equal(emitted[0][0], 'opened')
  const pending = state.deleteAccount()
  await state.deleteAccount()
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/me/account')
  assert.equal(calls[0][1].method, 'DELETE')
  assert.equal(calls[0][1].body.confirmed, true)
  assert.notEqual(user.value, null)
  resolve({ data: { deleted: true } })
  await pending
  assert.equal(user.value, null)
  assert.equal(state.open.value, false)
  assert.equal(navigation[0].query.deleted, '1')
})

test('a failed deletion leaves the account and confirmation intact, allowing retry', async () => {
  let fail = true
  const { state, user, navigation } = await deletionState(async () => {
    if (fail) throw new Error('Deletion failed')
    return { data: { deleted: true } }
  })
  state.showConfirmation()
  await state.deleteAccount()
  assert.equal(state.error.value, 'Deletion failed')
  assert.equal(state.open.value, true)
  assert.equal(state.deleting.value, false)
  assert.equal(user.value.id, 9)
  assert.equal(navigation.length, 0)
  fail = false
  await state.deleteAccount()
  assert.equal(state.error.value, '')
  assert.equal(user.value, null)
})

test('deleted participants disable sending in open campaign and direct inquiry histories', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: async path => { requests.push(path) }, formatDate: () => '', formatMoney: () => '' },
  })
  state.selectedConversationId.value = 1
  state.conversations.value = [{ id: 1, readOnly: true, campaign: { status: 'open' } }]
  state.draft.value = 'A message'
  assert.equal(state.campaignChatClosed.value, true)
  assert.equal(state.campaignChatNotice.value, 'campaignChat.deletedAccountNotice')
  await state.sendMessage()
  assert.equal(requests.length, 0)
  state.selectedConversationId.value = null
  state.selectedInquiryId.value = 2
  state.inquiries.value = [{ id: 2, readOnly: true, canChat: true, status: 'accepted', creator: {}, company: {} }]
  assert.equal(state.campaignChatClosed.value, true)
  assert.equal(state.canSend.value, false)
  await state.sendMessage()
  assert.equal(requests.length, 0)
})
