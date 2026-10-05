import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

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
  pending.get('/me/conversations/2/messages')({ data: [{ id: 22, body: 'new chat' }], conversation: { id: 2, unreadCount: 0 } })
  await newRequest
  pending.get('/me/conversations/1/messages')({ data: [{ id: 11, body: 'old chat' }], conversation: { id: 1, unreadCount: 0 } })
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
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: () => new Promise((resolve) => { finishRead = resolve }), apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1, unreadCount: 0 }]
  state.selectedConversationId.value = 1
  const message = { id: 10, senderId: 2, body: 'new message', createdAt: '2026-10-05T17:00:00Z' }
  state.handleRealtimeUpdate({ detail: { notificationType: 'chat_message', conversationId: 1, message } })
  assert.equal(state.conversationMessages.value[0].body, 'new message')
  finishRead({ data: [message], conversation: { id: 1, unreadCount: 0 } })
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

test('the account sidebar messages badge uses the same reactive count as the header', async () => {
  const { state, unreadMessageCount } = await setupView('../src/components/account/AccountSidebar.vue', {}, {
    user: { accountType: 'creator', approved: true }, pendingRegistrations: 0, adminLayout: false,
  })
  unreadMessageCount.value = 3
  assert.equal(state.navigationItems.value.find((item) => item.route === 'messages').badge, 3)
  unreadMessageCount.value = 0
  assert.equal(state.navigationItems.value.find((item) => item.route === 'messages').badge, 0)
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
        if (path === '/me/conversations') return { data: [{ id: 1, unreadCount: 0 }, { id: 2, unreadCount: 1 }] }
        if (path === '/me/inquiries') return { data: [] }
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
  assert.ok(requests.includes('/me/conversations/2/messages'))
})

test('an inaccessible notification target does not fall back to an unrelated chat', async () => {
  const route = vue.reactive({ query: { conversation: '1' } })
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': {
      apiGet: async (path) => {
        requests.push(path)
        return { data: path === '/me/conversations' ? [{ id: 1, unreadCount: 0 }] : [] }
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
      apiGet: async (path) => ({ data: path === '/me/conversations' ? [{ id: 1, unreadCount: 2 }] : [], unreadCount: 3 }),
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
