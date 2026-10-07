import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

test('closed campaigns block sending drafts, while open campaigns and direct inquiries can send', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: async (path) => { requests.push(path) }, formatDate: () => '', formatMoney: () => '' },
  })
  state.selectedConversationId.value = 1
  state.conversations.value = [{ id: 1, campaign: { status: 'closed' } }]
  state.draft.value = 'A message that cannot be sent'
  assert.equal(state.campaignChatClosed.value, true)
  assert.equal(state.canSend.value, false)
  await state.sendMessage()
  assert.equal(requests.length, 0)
  assert.equal(state.draft.value, 'A message that cannot be sent')
  state.conversations.value[0].campaign.status = 'open'
  assert.equal(state.canSend.value, true)
  state.selectedConversationId.value = null
  state.selectedInquiryId.value = 2
  state.inquiries.value = [{ id: 2, status: 'accepted' }]
  assert.equal(state.campaignChatClosed.value, false)
  assert.equal(state.canSend.value, true)
})

test('a slow response for the previous conversation cannot replace the newly opened chat', async () => {
  const pending = new Map()
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': {
      apiGet: (path) => new Promise((resolve) => pending.set(path, resolve)),
      apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '',
    },
  })
  state.selectedConversationId.value = 1
  const oldRequest = state.loadMessages({ id: 1, threadType: 'campaign' })
  state.selectedConversationId.value = 2
  const newRequest = state.loadMessages({ id: 2, threadType: 'campaign' })
  pending.get('/me/conversations/2/messages?limit=50')({ data: [{ id: 22, body: 'new chat' }], conversation: { id: 2, unreadCount: 0 } })
  await newRequest
  pending.get('/me/conversations/1/messages?limit=50')({ data: [{ id: 11, body: 'old chat' }], conversation: { id: 1, unreadCount: 0 } })
  await oldRequest
  assert.equal(state.conversationMessages.value[0].body, 'new chat')
})

test('a realtime update in a hidden chat does not fetch and mark its messages read', async () => {
  let requests = 0
  const { state, documentTarget } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => { requests += 1 }, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  documentTarget.visibilityState = 'hidden'
  state.handleRealtimeUpdate({ detail: { notificationType: 'chat_message', conversationId: 1, message: { id: 1 } } })
  assert.equal(requests, 0)
})

test('an incoming Mercure message updates the conversation preview and badge directly', async () => {
  let requests = 0
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => { requests += 1 }, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1, unreadCount: 0, lastMessage: 'old message' }]
  state.handleRealtimeUpdate({ detail: {
    notificationType: 'chat_message', conversationId: 1,
    message: { id: 10, senderId: 2, body: 'new message', createdAt: '2026-10-05T17:00:00Z' },
  } })
  assert.equal(state.conversations.value[0].lastMessage, 'new message')
  assert.equal(state.conversations.value[0].unreadCount, 1)
  assert.equal(requests, 0)
})

test('an open chat displays the Mercure message before its read acknowledgement finishes', async () => {
  let finishRead
  const { state, windowTarget } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => { throw new Error('A live message must not refetch history') }, apiRequest: () => new Promise((resolve) => { finishRead = resolve }), formatDate: () => '', formatMoney: () => '' },
  })
  windowTarget.requestAnimationFrame = (callback) => { callback(); return 1 }
  state.messageList.value = { scrollHeight: 500, clientHeight: 300, scrollTop: 200 }
  state.conversations.value = [{ id: 1, unreadCount: 0 }]
  state.selectedConversationId.value = 1
  const message = { id: 10, senderId: 2, body: 'new message', createdAt: '2026-10-05T17:00:00Z' }
  state.handleRealtimeUpdate({ detail: { notificationType: 'chat_message', conversationId: 1, message } })
  assert.equal(state.conversationMessages.value[0].body, 'new message')
  await new Promise((resolve) => setImmediate(resolve))
  finishRead({ data: { readAt: '2026-10-05T17:00:01Z' }, conversation: { id: 1, unreadCount: 0 } })
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(state.conversationMessages.value.length, 1)
  assert.equal(state.conversations.value[0].unreadCount, 0)
})

test('Mercure reconnect retains its event cursor and repeated notifications are ignored', async () => {
  const sources = []
  class EventSource {
    constructor(url, options) { this.url = url; this.options = options; sources.push(this) }
    close() {}
  }
  const { state, windowTarget } = await setupView('../src/App.vue', {
    './lib/api': {
      apiGet: async (path) => path === '/me/realtime'
        ? { data: { hubUrl: 'https://hub.example.test/.well-known/mercure', topic: 'https://wave.local/users/1' } }
        : { data: [], unreadCount: 1 },
      apiRequest: async () => {}, formatDate: () => '',
    },
  }, {}, { EventSource })
  let delivered = 0
  windowTarget.addEventListener('wave:realtime', () => { delivered += 1 })
  await state.connectRealtime()
  const event = { lastEventId: 'urn:uuid:event-10', data: JSON.stringify({ notificationId: 10, notificationType: 'chat_message', conversationId: 1, message: { senderId: 2 } }) }
  sources[0].onmessage(event)
  sources[0].onmessage(event)
  assert.equal(delivered, 1)
  state.closeRealtime()
  await state.connectRealtime()
  assert.equal(sources[1].url.searchParams.get('lastEventID'), 'urn:uuid:event-10')
  assert.equal(sources[1].options.withCredentials, true)
})

test('closing a pending Mercure authorization prevents a stale connection from opening', async () => {
  let finishAuthorization
  let connections = 0
  const { state } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: () => new Promise((resolve) => { finishAuthorization = resolve }), apiRequest: async () => {}, formatDate: () => '' },
  }, {}, { EventSource: class { constructor() { connections += 1 } } })
  const connection = state.connectRealtime()
  state.closeRealtime()
  finishAuthorization({ data: { hubUrl: 'https://hub.example.test/.well-known/mercure', topic: 'https://wave.local/users/1' } })
  await connection
  assert.equal(connections, 0)
})

test('the sidebar omits media kit and messages while retaining account workflow routes', async () => {
  const { state } = await setupView('../src/components/account/AccountSidebar.vue', {}, {
    user: { accountType: 'creator', approved: true }, pendingRegistrations: 0, adminLayout: false,
  })
  assert.ok(!state.navigationItems.value.some((item) => ['account', 'messages'].includes(item.route)))
  assert.ok(state.navigationItems.value.some((item) => item.route === 'account-applications'))
  assert.deepEqual(Array.from(state.exploreItems.value, (item) => item.route), ['creators'])
})

test('the service worker displays a push and tells open windows to reload authorized inbox data', async () => {
  const handlers = new Map()
  const displayed = []
  const posted = []
  const self = {
    __WB_MANIFEST: [],
    addEventListener: (type, callback) => handlers.set(type, callback),
    registration: { showNotification: async (...args) => displayed.push(args) },
    clients: { matchAll: async () => [{ postMessage: (message) => posted.push(message) }] },
  }
  const context = vm.createContext({ self })
  const source = await readFile(new URL('../src/sw.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(async (specifier) => {
    if (specifier === './lib/appBadge') {
      return new vm.SourceTextModule(await readFile(new URL('../src/lib/appBadge.js', import.meta.url), 'utf8'), { context })
    }
    if (specifier === './lib/notificationNavigation') {
      return new vm.SourceTextModule(await readFile(new URL('../src/lib/notificationNavigation.js', import.meta.url), 'utf8'), { context })
    }
    const name = specifier === 'workbox-core' ? 'clientsClaim' : 'precacheAndRoute'
    return new vm.SyntheticModule([name], function () { this.setExport(name, () => {}) }, { context })
  })
  await module.evaluate()
  let completion
  handlers.get('push')({ data: { json: () => ({ title: 'Company', body: 'Hello', url: '/messages?conversation=1' }) }, waitUntil: (promise) => { completion = promise } })
  await completion
  assert.equal(displayed[0][0], 'Company')
  assert.equal(displayed[0][1].body, 'Hello')
  assert.equal(posted[0].type, 'WAVE_PUSH_RECEIVED')
  assert.equal(Object.keys(posted[0]).length, 1)
})

test('a notification chat query switches the already mounted Messages view', async () => {
  const route = vue.reactive({ query: { conversation: '1' } })
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': {
      apiGet: async (path) => {
        requests.push(path)
        if (path.startsWith('/me/inbox?')) return { data: [{ id: 1, threadType: 'campaign', unreadCount: 0 }, { id: 2, threadType: 'campaign', unreadCount: 1 }], meta: { hasMore: false } }
        if (path === '/me/inbox/campaign/2') return { data: { id: 2, threadType: 'campaign', unreadCount: 1 } }
        return { data: [{ id: 20, body: 'notification target' }], conversation: { id: 2, unreadCount: 0 } }
      },
      apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '',
    },
  })
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 10, body: 'previous chat' }]
  route.query.conversation = '2'
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(state.selectedConversationId.value, 2)
  assert.equal(state.conversationMessages.value[0].body, 'notification target')
  assert.ok(requests.includes('/me/conversations/2/messages?limit=50'))
})

test('an inaccessible notification target does not fall back to an unrelated chat', async () => {
  const route = vue.reactive({ query: { conversation: '1' } })
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': {
      apiGet: async (path) => {
        requests.push(path)
        if (path.startsWith('/me/inbox?')) return { data: [{ id: 1, threadType: 'campaign', unreadCount: 0 }], meta: { hasMore: false } }
        throw Object.assign(new Error('Not accessible'), { status: 404 })
      },
      apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '',
    },
  })
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 10, body: 'previous chat' }]
  route.query.conversation = '999'
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(state.selectedConversationId.value, null)
  assert.equal(state.conversationMessages.value.length, 0)
  assert.equal(requests.some((path) => path.endsWith('/messages')), false)
})

test('the bell uses the full unread total, including notifications outside its menu', async () => {
  const { state } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: async () => ({ data: [{ id: 1, readAt: null }], unreadCount: 42 }), apiRequest: async () => {}, formatDate: () => '' },
  })
  await state.loadNotifications()
  assert.equal(state.notifications.value.length, 1)
  assert.equal(state.unreadNotificationCount.value, 42)
})

test('the app badge waits for authorized counts, follows reads, and clears on logout', async () => {
  const badges = []
  const { state, unreadMessageCount, currentUser } = await setupView('../src/App.vue', {
    './lib/appBadge': { updateAppBadge: async (count) => { badges.push(count) } },
    './lib/api': {
      apiGet: async (path) => ({ data: [], unreadCount: path === '/me/inbox/unread' ? 2 : 3 }),
      apiRequest: async () => {}, formatDate: () => '',
    },
  })
  await state.loadNotifications()
  await vue.nextTick()
  assert.equal(badges.length, 0)
  await state.loadUnreadMessages()
  await vue.nextTick()
  assert.equal(badges.at(-1), 5)
  unreadMessageCount.value = 0
  await vue.nextTick()
  assert.equal(badges.at(-1), 3)
  currentUser.value = null
  await vue.nextTick()
  assert.equal(badges.at(-1), 0)
})

test('mobile keyboard spacing clears the home inset and restores it on close', async () => {
  const { state, windowTarget, documentTarget } = await setupView('../src/views/MessagesView.vue')
  windowTarget.matchMedia = () => ({ matches: true })
  windowTarget.innerHeight = 844
  documentTarget.documentElement.clientHeight = 844
  windowTarget.visualViewport = { height: 844, offsetTop: 0, scale: 1 }
  state.updateViewport()
  assert.equal(state.viewportStyle.value['--chat-bottom-inset'], 'env(safe-area-inset-bottom, 0px)')

  // iOS can resize innerHeight as well; the layout viewport still identifies the keyboard.
  windowTarget.innerHeight = 480
  windowTarget.visualViewport.height = 480
  state.updateViewport()
  assert.equal(state.viewportStyle.value['--chat-bottom-inset'], '0px')
  assert.equal(state.viewportStyle.value['--chat-viewport-height'], '480px')

  windowTarget.innerHeight = 844
  windowTarget.visualViewport.height = 844
  state.updateViewport()
  assert.equal(state.viewportStyle.value['--chat-bottom-inset'], 'env(safe-area-inset-bottom, 0px)')
})

test('browser chrome, zoom, and desktop resizing do not remove the home inset', async () => {
  const { state, windowTarget, documentTarget } = await setupView('../src/views/MessagesView.vue')
  windowTarget.matchMedia = () => ({ matches: true })
  windowTarget.innerHeight = 844
  documentTarget.documentElement.clientHeight = 844
  windowTarget.visualViewport = { height: 780, offsetTop: 0, scale: 1 }
  state.updateViewport()
  assert.equal(state.keyboardOpen.value, false)
  windowTarget.visualViewport = { height: 480, offsetTop: 0, scale: 2 }
  state.updateViewport()
  assert.equal(state.keyboardOpen.value, false)
  windowTarget.matchMedia = () => ({ matches: false })
  windowTarget.visualViewport.scale = 1
  state.updateViewport()
  assert.equal(state.keyboardOpen.value, false)
})

test('relative chat times refresh locally and stop their timer while the page is hidden', async () => {
  let now = Date.parse('2026-10-05T19:00:20Z')
  let requests = 0
  const { state, documentTarget, windowTarget } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': {
      apiGet: () => { requests += 1 }, apiRequest: () => { requests += 1 },
      formatDate: () => '', formatMoney: () => '',
    },
  }, {}, { Date: class extends Date { static now() { return now } } })
  const scheduled = []
  windowTarget.setTimeout = (callback, delay) => {
    scheduled.push({ callback, delay })
    return scheduled.length
  }
  state.updateChatClock()
  assert.equal(scheduled[0].delay, 40_000)
  now += 40_000
  scheduled[0].callback()
  assert.equal(state.chatClock.value, now)
  assert.equal(scheduled[1].delay, 60_000)
  documentTarget.visibilityState = 'hidden'
  state.updateChatClock()
  assert.equal(scheduled.length, 2)
  documentTarget.visibilityState = 'visible'
  now += 86_400_000
  state.updateChatClock()
  assert.equal(state.chatClock.value, now)
  assert.equal(scheduled.length, 3)
  assert.equal(requests, 0)
})

test('older pages preserve their anchor, deduplicate live messages, and never acknowledge a read', async () => {
  let finishHistory
  let writes = 0
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': {
      apiGet: (path) => { assert.equal(path, '/me/conversations/1/messages?limit=50&before=51'); return new Promise((resolve) => { finishHistory = resolve }) },
      apiRequest: () => { writes += 1 }, formatDate: () => '', formatMoney: () => '',
    },
  })
  state.conversations.value = [{ id: 1, unreadCount: 2 }]
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 51, senderId: 2, body: 'anchor' }]
  state.hasOlderMessages.value = true
  state.atLatestMessage.value = false
  let anchorTop = 100
  const anchor = { dataset: { messageId: '51' }, getBoundingClientRect: () => ({ top: anchorTop }) }
  state.messageList.value = { scrollTop: 15, querySelector: () => anchor }
  const history = state.loadOlderMessages()
  // Duplicate scroll events cannot start another request.
  await state.loadOlderMessages()
  state.handleRealtimeUpdate({ detail: { notificationType: 'chat_message', conversationId: 1, message: { id: 52, senderId: 2, body: 'arrived during history' } } })
  anchorTop = 600
  finishHistory({ data: [{ id: 50, senderId: 2 }, { id: 51, senderId: 2, body: 'anchor' }], meta: { hasMoreOlder: false } })
  await history
  assert.deepEqual(Array.from(state.conversationMessages.value, (message) => message.id), [50, 51, 52])
  assert.equal(state.messageList.value.scrollTop, 515)
  assert.equal(state.hasOlderMessages.value, false)
  assert.equal(state.unseenNewMessages.value, 1)
  assert.equal(writes, 0)
})

test('an older response cannot enter another chat after switching threads', async () => {
  let finishHistory
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: () => new Promise((resolve) => { finishHistory = resolve }), apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1 }, { id: 2 }]
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 51 }]
  state.hasOlderMessages.value = true
  const history = state.loadOlderMessages()
  state.resetHistory()
  state.selectedConversationId.value = 2
  state.conversationMessages.value = [{ id: 80, body: 'other chat' }]
  finishHistory({ data: [{ id: 1 }], meta: { hasMoreOlder: true } })
  await history
  assert.equal(state.conversationMessages.value.length, 1)
  assert.equal(state.conversationMessages.value[0].id, 80)
})

test('reconnection catches up through bounded newer pages without dropping older history', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': {
      apiGet: async (path) => {
        requests.push(path)
        return requests.length === 1
          ? { data: [{ id: 52, senderId: 2 }], meta: { hasMoreNewer: true } }
          : { data: [{ id: 53, senderId: 2 }], meta: { hasMoreNewer: false } }
      },
      apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '',
    },
  })
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 1 }, { id: 51 }]
  state.hasOlderMessages.value = true
  state.atLatestMessage.value = false
  await state.loadMessages({ id: 1, threadType: 'campaign' })
  assert.deepEqual(requests, ['/me/conversations/1/messages?limit=50&after=51', '/me/conversations/1/messages?limit=50&after=52'])
  assert.deepEqual(Array.from(state.conversationMessages.value, (message) => message.id), [1, 51, 52, 53])
  assert.equal(state.hasOlderMessages.value, true)
})

test('seen receipts affect only our outgoing messages in the matching chat and survive older merges', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  state.conversationMessages.value = [{ id: 4, senderId: 1 }, { id: 5, senderId: 2 }, { id: 6, senderId: 1 }]
  state.handleRealtimeUpdate({ detail: { type: 'chat_read', conversationId: 2, readerId: 2, throughId: 6, readAt: 'wrong chat' } })
  assert.equal(state.conversationMessages.value[0].readAt, undefined)
  state.handleRealtimeUpdate({ detail: { type: 'chat_read', conversationId: 1, readerId: 2, throughId: 4, readAt: '2026-10-05T17:00:00Z' } })
  state.mergeMessages([{ id: 3, senderId: 1, readAt: null }])
  assert.equal(state.conversationMessages.value[0].readAt, '2026-10-05T17:00:00Z')
  assert.equal(state.conversationMessages.value[1].readAt, '2026-10-05T17:00:00Z')
  assert.equal(state.conversationMessages.value[2].readAt, undefined)
  assert.equal(state.conversationMessages.value[3].readAt, undefined)
})

test('hidden chats and readers of older history do not send seen receipts', async () => {
  let writes = 0
  const { state, documentTarget } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: () => { writes += 1 }, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  state.messageList.value = {}
  state.conversationMessages.value = [{ id: 1, senderId: 2 }]
  documentTarget.visibilityState = 'hidden'
  await state.acknowledgeVisibleMessages()
  documentTarget.visibilityState = 'visible'
  state.atLatestMessage.value = false
  await state.acknowledgeVisibleMessages()
  assert.equal(writes, 0)
})

test('private read events do not increment notification badges or fetch the inbox', async () => {
  const sources = []
  let requests = 0
  const { state, unreadMessageCount, windowTarget } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: async () => { requests += 1; return { data: { hubUrl: 'https://hub.example.test/.well-known/mercure', topic: 'https://wave.local/users/1' } } }, apiRequest: async () => {}, formatDate: () => '' },
  }, {}, { EventSource: class { constructor() { sources.push(this) } close() {} } })
  let delivered = 0
  windowTarget.addEventListener('wave:realtime', () => { delivered += 1 })
  await state.connectRealtime()
  sources[0].onmessage({ data: JSON.stringify({ type: 'chat_read', conversationId: 1, throughId: 42 }) })
  assert.equal(delivered, 1)
  assert.equal(requests, 1)
  assert.equal(unreadMessageCount.value, 0)
})

test('direct inquiry messages without notification IDs reach the chat once and update the shared badge', async () => {
  const sources = []
  const { state, unreadMessageCount, windowTarget } = await setupView('../src/App.vue', {
    'vue-router': { isNavigationFailure: () => false, NavigationFailureType: { duplicated: 16 }, RouterView: {}, useRoute: () => ({ meta: { routeName: 'messages' }, query: { inquiry: '4' } }), useRouter: () => ({ replace: async () => {} }) },
    './lib/api': { apiGet: async () => ({ data: { hubUrl: 'https://hub.example.test/.well-known/mercure', topic: 'https://wave.local/users/1' } }), apiRequest: async () => {}, formatDate: () => '' },
  }, {}, { EventSource: class { constructor() { sources.push(this) } close() {} } })
  let delivered = 0
  windowTarget.addEventListener('wave:realtime', () => { delivered += 1 })
  await state.connectRealtime()
  const event = { data: JSON.stringify({ type: 'chat_message', notificationType: 'chat_message', inquiryId: 4, message: { id: 42, senderId: 2 } }) }
  sources[0].onmessage(event)
  sources[0].onmessage(event)
  assert.equal(delivered, 1)
  assert.equal(unreadMessageCount.value, 1)
})

test('a new-message divider starts at the first incoming unread message and survives read acknowledgement', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: async () => ({ data: { readAt: '2026-10-06T00:00:00Z' }, conversation: { id: 1, unreadCount: 0 } }), formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  const messages = [
    { id: 1, senderId: 2, readAt: '2026-10-05T23:00:00Z' },
    { id: 2, senderId: 1, readAt: null },
    { id: 3, senderId: 2, readAt: null },
    { id: 4, senderId: 2, readAt: null },
  ]
  state.rememberUnreadBoundary(messages, 3)
  state.mergeMessages(messages)
  assert.equal(state.unreadDividerMessageId.value, 3)
  state.messageList.value = {}
  await state.acknowledgeVisibleMessages()
  assert.ok(state.conversationMessages.value[2].readAt)
  assert.ok(state.conversationMessages.value[3].readAt)
  assert.equal(state.unreadDividerMessageId.value, 3)
  state.resetHistory()
  assert.equal(state.unreadDividerMessageId.value, null)
  state.mergeMessages(messages.map((message) => ({ ...message, readAt: 'already read' })))
  state.rememberUnreadBoundary(state.conversationMessages.value)
  assert.equal(state.unreadDividerMessageId.value, null)
})

test('an unread boundary outside the latest page remains discoverable after older pages load', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  const latestPage = [{ id: 76, senderId: 2 }, { id: 125, senderId: 2 }]
  state.rememberUnreadBoundary(latestPage, 1)
  state.mergeMessages(latestPage)
  assert.equal(state.firstUnreadMessageId.value, 1)
  assert.equal(state.unreadDividerMessageId.value, 76)
  assert.equal(state.hasEarlierUnreadMessages.value, true)
  // The retained boundary still works if an acknowledgement changed server readAt.
  state.rememberUnreadBoundary([{ id: 1, senderId: 2, readAt: 'now read' }])
  state.mergeMessages([{ id: 1, senderId: 2, readAt: 'now read' }])
  assert.equal(state.unreadDividerMessageId.value, 1)
  assert.equal(state.hasEarlierUnreadMessages.value, false)
})

test('unseen live messages share one divider, while visible live messages do not create one', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.conversations.value = [{ id: 1, unreadCount: 0 }]
  state.selectedConversationId.value = 1
  const incoming = (id) => state.handleRealtimeUpdate({ detail: { notificationType: 'chat_message', conversationId: 1, message: { id, senderId: 2, body: `Message ${id}` } } })
  incoming(1)
  assert.equal(state.unreadDividerMessageId.value, null)
  state.atLatestMessage.value = false
  incoming(2)
  incoming(3)
  assert.equal(state.unreadDividerMessageId.value, 2)
  assert.equal(state.unseenNewMessages.value, 2)
})

test('message grouping breaks at the unread divider, including direct inquiries', async () => {
  const chatTime = await import('../src/lib/chatTime.js')
  const { state } = await setupView('../src/views/MessagesView.vue', { '../lib/chatTime': chatTime })
  state.inquiries.value = [{ id: 1, canChat: true }]
  state.selectedInquiryId.value = 1
  const messages = [
    { id: 1, senderRole: 'company', senderId: 2, createdAt: '2026-10-06T00:00:00Z', readAt: 'read' },
    { id: 2, senderRole: 'creator', senderId: 1, createdAt: '2026-10-06T00:00:10Z', readAt: null },
    { id: 3, senderRole: 'company', senderId: 2, createdAt: '2026-10-06T00:00:20Z', readAt: null },
    { id: 4, senderRole: 'company', senderId: 2, createdAt: '2026-10-06T00:00:30Z', readAt: null },
  ]
  state.rememberUnreadBoundary(messages)
  state.mergeMessages(messages)
  assert.equal(state.unreadDividerMessageId.value, 3)
  assert.equal(state.chatMessagesAreGrouped(messages[0], messages[2]), false)
  assert.equal(state.chatMessagesAreGrouped(messages[2], messages[3]), true)
})

test('opening a chat scrolls to its unread divider and does not mark later offscreen messages read', async () => {
  let writes = 0
  const frames = []
  const { state, windowTarget } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': {
      apiGet: async () => ({ data: [{ id: 1, senderId: 2, readAt: 'read' }, { id: 2, senderId: 2, readAt: null }, { id: 3, senderId: 2, readAt: null }], meta: { firstUnreadId: 2 }, conversation: { id: 1, unreadCount: 2 } }),
      apiRequest: () => { writes += 1 }, formatDate: () => '', formatMoney: () => '',
    },
  })
  windowTarget.requestAnimationFrame = (callback) => { frames.push(callback); return frames.length }
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  const divider = { getBoundingClientRect: () => ({ top: 500 }) }
  state.messageList.value = { scrollTop: 0, scrollHeight: 1000, clientHeight: 200, querySelector: () => divider, getBoundingClientRect: () => ({ top: 0 }) }
  await state.loadMessages({ id: 1, threadType: 'campaign' }, true)
  frames.at(-1)()
  assert.equal(state.messageList.value.scrollTop, 488)
  assert.equal(state.atLatestMessage.value, false)
  assert.equal(writes, 0)
})
