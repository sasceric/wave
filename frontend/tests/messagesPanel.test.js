import assert from 'node:assert/strict'
import { test } from 'node:test'
import { groupInboxThreads } from '../src/lib/inboxGroups.js'
import { createChatTimeFormatter } from '../src/lib/chatTime.js'
import { setupView } from './setupView.js'

const card = (id, threadType = 'campaign') => ({
  id, threadType, threadKey: `${threadType}-${id}`, unreadCount: 3,
  creator: { displayName: 'Creator Name', avatarUrl: '/creator.jpg' },
  company: { name: 'Company Name', logoUrl: '/company.jpg' },
  lastMessageAt: '2026-10-08T10:00:00Z', lastMessage: 'Latest message',
})
const modules = apiGet => ({
  '../../lib/api': { apiGet },
  '../../lib/inboxGroups': { groupInboxThreads },
  '../../lib/chatTime': { createChatTimeFormatter },
})

test('the header preview requests five latest chats without acknowledging unread messages', async () => {
  const requests = []
  const { state } = await setupView('../src/components/shared/MessagesPanel.vue', modules(async path => {
    requests.push(path)
    return { data: [card(1), card(2, 'inquiry'), card(3), card(4), card(5), card(6)] }
  }), { user: { id: 1, accountType: 'creator' } })
  await state.loadThreads()
  assert.deepEqual(requests, ['/me/inbox?limit=5&filter=all'])
  assert.equal(state.threads.value.length, 5)
  assert.equal(state.threads.value[0].unreadCount, 3)
  assert.equal(state.name(card(1)), 'Company Name')
  assert.equal(state.avatar(card(1)), '/company.jpg')
  assert.equal(state.destination(card(1)).query.conversation, 1)
  assert.equal(state.destination(card(2, 'inquiry')).query.inquiry, 2)
})

test('company previews show the creator and reject stale refresh results', async () => {
  const pending = []
  const { state } = await setupView('../src/components/shared/MessagesPanel.vue', modules(() => new Promise(resolve => pending.push(resolve))), {
    user: { id: 2, accountType: 'company' },
  })
  const old = state.loadThreads()
  const current = state.loadThreads()
  pending[1]({ data: [card(2)] })
  await current
  pending[0]({ data: [card(1)] })
  await old
  assert.equal(state.threads.value[0].id, 2)
  assert.equal(state.name(card(2)), 'Creator Name')
  assert.equal(state.avatar(card(2)), '/creator.jpg')
})

test('a failed preview can retry and cannot expose results from a different account', async () => {
  let finish
  let failed = true
  const props = { user: { id: 1, accountType: 'creator' } }
  const { state } = await setupView('../src/components/shared/MessagesPanel.vue', modules(() => {
    if (failed) return Promise.reject(new Error('Offline'))
    return new Promise(resolve => { finish = resolve })
  }), props)
  await state.loadThreads()
  assert.equal(state.error.value, 'Offline')
  failed = false
  const retry = state.loadThreads()
  props.user = { id: 2, accountType: 'creator' }
  finish({ data: [card(1)] })
  await retry
  assert.equal(state.threads.value.length, 0)
  assert.equal(state.loaded.value, false)
  const success = state.loadThreads()
  finish({ data: [card(2)] })
  await success
  assert.equal(state.loaded.value, true)
  assert.equal(state.error.value, '')
})
