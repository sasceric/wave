import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { compile } from '@vue/compiler-dom'
import * as vue from 'vue'
import { setupView } from './setupView.js'

const card = (id, type = 'campaign') => ({
  id, threadType: type, threadKey: `${type}-${id}`, canChat: true,
  campaign: { title: `Campaign ${id}` }, creator: { displayName: 'Creator' }, company: { name: 'Company' },
  lastMessage: `Preview ${id}`, lastMessageAt: '2026-10-01T12:00:00Z', unreadCount: 1,
})
const page = (data, cursor = null) => ({ data, meta: { hasMore: Boolean(cursor), nextCursor: cursor } })
const modules = (apiGet) => ({ '../lib/api': { apiGet, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' } })
const settle = () => new Promise((resolve) => setImmediate(resolve))

test('the campaign brief waits for full thread metadata and survives summary-only inbox refreshes', async () => {
  let finishMetadata
  const { state } = await setupView('../src/views/MessagesView.vue', modules((path) => {
    if (path === '/me/inbox/campaign/1') return new Promise((resolve) => { finishMetadata = resolve })
    return Promise.resolve({ data: [] })
  }))
  const source = await readFile(new URL('../src/views/MessagesView.vue', import.meta.url), 'utf8')
  const start = source.indexOf('<aside\n        v-if="selectedCampaign')
  const brief = source.slice(start, source.indexOf('</aside>', start) + '</aside>'.length)
  const render = vm.runInNewContext(`(function () { ${compile(brief, { mode: 'function' }).code} })()`, {
    Vue: { ...vue, resolveComponent: (name) => name },
  })
  const context = vue.proxyRefs(state)
  state.conversations.value = [card(1)]
  const selection = state.selectConversation(card(1), false)
  // The real template used to call deliverables.join() on this summary row.
  assert.equal(render(context, []).type, vue.Comment)
  finishMetadata({ data: { ...card(1), campaign: {
    title: 'Complete campaign', slug: 'complete', status: 'open',
    deliverables: ['One video'], channels: ['Instagram'], closesAt: '2026-11-01',
  } } })
  await selection
  assert.equal(render(context, []).props.id, 'campaign-details-panel')
  state.upsertConversation(card(1))
  assert.deepEqual(Array.from(state.selectedCampaign.value.deliverables), ['One video'])
  assert.equal(render(context, []).props.id, 'campaign-details-panel')
})

test('inbox batches merge both chat types, deduplicate rows, and stop at the last cursor', async () => {
  const requests = []
  const responses = [page([card(1), card(1, 'inquiry')], 'next'), page([card(1), card(2)], null)]
  const { state } = await setupView('../src/views/MessagesView.vue', modules(async (path) => { requests.push(path); return responses.shift() }))
  await state.loadInboxPage()
  assert.equal(state.threads.value.length, 2)
  await state.loadInboxPage()
  assert.equal(state.threads.value.length, 3)
  assert.equal(state.hasMoreInbox.value, false)
  await state.loadInboxPage()
  assert.deepEqual(requests, ['/me/inbox?limit=30&filter=all', '/me/inbox?limit=30&filter=all&cursor=next'])
})

test('inbox retry retains the cursor and loaded rows, and overlapping intersections do not fetch twice', async () => {
  let finish
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', modules((path) => {
    requests.push(path)
    if (requests.length === 1) return Promise.resolve(page([card(1)], 'older'))
    return new Promise((resolve, reject) => { finish = { resolve, reject } })
  }))
  await state.loadInboxPage()
  const failed = state.loadInboxPage()
  await state.loadInboxPage()
  assert.equal(requests.length, 2)
  assert.equal(state.threads.value.length, 1)
  finish.reject(new Error('Network unavailable'))
  await failed
  assert.equal(state.inboxPageError.value, 'Network unavailable')
  const retry = state.loadInboxPage()
  assert.equal(requests[1], requests[2])
  finish.resolve(page([card(2)]))
  await retry
  assert.equal(state.threads.value.length, 2)
})

test('search invalidates an older page immediately, debounces requests, and preserves the selected chat', async () => {
  let finishOld
  const requests = []
  const { state, windowTarget } = await setupView('../src/views/MessagesView.vue', modules((path) => {
    requests.push(path)
    if (requests.length === 1) return Promise.resolve(page([card(1)], 'older'))
    if (requests.length === 2) return new Promise((resolve) => { finishOld = resolve })
    return Promise.resolve(page([card(50)]))
  }))
  let timer
  windowTarget.setTimeout = (callback) => { timer = callback; return 1 }
  await state.loadInboxPage()
  state.selectedDetails.value = card(1)
  state.selectedConversationId.value = 1
  const old = state.loadInboxPage()
  state.conversationQuery.value = 'Company'
  assert.equal(state.loadingInboxPage.value, true)
  assert.equal(state.threads.value.length, 0)
  assert.equal(state.selectedThread.value.id, 1)
  finishOld(page([card(2)], 'stale'))
  await old
  assert.equal(state.threads.value.length, 0)
  timer()
  await settle()
  assert.equal(requests[2], '/me/inbox?limit=30&filter=all&q=Company')
  assert.equal(state.threads.value[0].id, 50)
})

test('event-driven head refresh keeps loaded history and its cursor', async () => {
  const requests = []
  const responses = [page([card(1)], 'second'), page([card(2)], 'third'), page([card(3)], 'second'), page([card(4)])]
  const { state } = await setupView('../src/views/MessagesView.vue', modules(async (path) => { requests.push(path); return responses.shift() }))
  await state.loadInboxPage()
  await state.loadInboxPage()
  await state.refreshInbox()
  assert.equal(state.threads.value.length, 3)
  await state.loadInboxPage()
  assert.ok(requests.at(-1).endsWith('cursor=third'))
  assert.equal(state.threads.value.length, 4)
})

test('an unloaded Mercure chat fetches only that authorized thread and adds its preview', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', modules(async (path) => { requests.push(path); return { data: card(80, 'inquiry') } }))
  state.handleRealtimeUpdate({ detail: { type: 'chat_message', inquiryId: 80, message: { id: 1, senderId: 2, body: 'Live message' } } })
  await settle()
  assert.deepEqual(requests, ['/me/inbox/inquiry/80'])
  assert.equal(state.inquiries.value[0].unreadCount, 1)
  assert.equal(state.threads.value[0].id, 80)
})

test('a push deep link opens an inquiry that is outside the first inbox batch', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    ...modules(async (path) => {
      requests.push(path)
      if (path.startsWith('/me/inbox?')) return page([card(1)], 'older')
      if (path === '/me/inbox/inquiry/80') return { data: card(80, 'inquiry') }
      return { data: [{ id: 4, senderId: 2, senderRole: 'company', body: 'Notification target' }] }
    }),
    'vue-router': { useRoute: () => ({ query: { inquiry: '80' } }), useRouter: () => ({ replace: async () => {} }) },
  })
  await state.loadInbox()
  assert.equal(state.selectedInquiryId.value, 80)
  assert.equal(state.selectedThread.value.id, 80)
  assert.equal(state.conversationMessages.value[0].body, 'Notification target')
  assert.deepEqual(requests, ['/me/inbox?limit=30&filter=all', '/me/inbox/inquiry/80', '/me/inquiries/80/messages?limit=50'])
})

test('slow thread metadata cannot replace a more recently selected chat', async () => {
  const metadata = new Map()
  const { state } = await setupView('../src/views/MessagesView.vue', modules((path) => {
    if (path.startsWith('/me/inbox/')) return new Promise((resolve) => metadata.set(path, resolve))
    return Promise.resolve({ data: [{ id: 22, body: 'Current chat' }] })
  }))
  const old = state.selectConversation(card(1), false)
  const current = state.selectConversation(card(2), false)
  metadata.get('/me/inbox/campaign/2')({ data: card(2) })
  await current
  metadata.get('/me/inbox/campaign/1')({ data: card(1) })
  await old
  assert.equal(state.selectedThread.value.id, 2)
  assert.equal(state.conversationMessages.value[0].body, 'Current chat')
})

test('the shared badge fetches an aggregate count rather than chat resources', async () => {
  const requests = []
  const { state, unreadMessageCount } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: async (path) => { requests.push(path); return { unreadCount: 65 } }, apiRequest: async () => {}, formatDate: () => '' },
  })
  await state.loadUnreadMessages()
  assert.deepEqual(requests, ['/me/inbox/unread'])
  assert.equal(unreadMessageCount.value, 65)
})

test('a stale inbox batch cannot undo a Mercure preview or unread count', async () => {
  let finish
  const { state } = await setupView('../src/views/MessagesView.vue', modules(() => new Promise((resolve) => { finish = resolve })))
  state.conversations.value = [card(1)]
  const request = state.loadInboxPage(true)
  state.handleRealtimeUpdate({ detail: { type: 'chat_message', conversationId: 1,
    message: { id: 5, senderId: 2, body: 'New live message', createdAt: '2026-10-02T12:00:00Z' } } })
  finish(page([card(1)]))
  await request
  assert.equal(state.conversations.value[0].lastMessage, 'New live message')
  assert.equal(state.conversations.value[0].unreadCount, 2)
})

test('reading a selected inquiry updates its sidebar row and retains newer incoming messages', async () => {
  let finish
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: () => new Promise((resolve) => { finish = resolve }), formatDate: () => '', formatMoney: () => '' },
  })
  state.inquiries.value = [card(1, 'inquiry')]
  state.selectedInquiryId.value = 1
  state.selectedDetails.value = card(1, 'inquiry')
  state.messageList.value = {}
  state.conversationMessages.value = [{ id: 1, senderId: 2, senderRole: 'company', readAt: null }]
  const read = state.acknowledgeVisibleMessages()
  state.handleRealtimeUpdate({ detail: { type: 'chat_message', inquiryId: 1,
    message: { id: 2, senderId: 2, senderRole: 'company', body: 'Arrived during read' } } })
  // Keep the follow-up acknowledgement pending rather than hiding the unread row.
  state.atLatestMessage.value = false
  finish({ data: { readAt: '2026-10-02T12:00:00Z' } })
  await read
  assert.equal(state.inquiries.value[0].unreadCount, 1)
  assert.equal(state.selectedDetails.value.unreadCount, 1)
})

test('a Mercure message arriving during chat selection does not suppress the initial history page', async () => {
  let finishMetadata
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', modules((path) => {
    requests.push(path)
    if (path === '/me/inbox/campaign/1') return new Promise((resolve) => { finishMetadata = resolve })
    return Promise.resolve({ data: [{ id: 1, senderId: 2, body: 'Earlier message' }], meta: { hasMoreOlder: true } })
  }))
  state.conversations.value = [card(1)]
  const selection = state.selectConversation(card(1), false)
  state.handleRealtimeUpdate({ detail: { type: 'chat_message', conversationId: 1,
    message: { id: 2, senderId: 2, body: 'Message during selection', createdAt: '2026-10-02T12:00:00Z' } } })
  finishMetadata({ data: card(1) })
  await selection
  assert.ok(requests.includes('/me/conversations/1/messages?limit=50'))
  assert.equal(requests.some((path) => path.includes('after=')), false)
  assert.deepEqual(Array.from(state.conversationMessages.value, (message) => message.id), [1, 2])
  assert.equal(state.hasOlderMessages.value, true)
})
