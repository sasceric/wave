import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'

async function setupView(path, extraModules = {}, props = {}, globals = {}) {
  const currentUser = vue.ref({ id: 1, accountType: 'creator' })
  const unreadMessageCount = vue.ref(0)
  const windowTarget = new EventTarget()
  windowTarget.requestAnimationFrame = () => 1
  windowTarget.cancelAnimationFrame = () => {}
  windowTarget.setTimeout = () => 1
  windowTarget.clearTimeout = () => {}
  const documentTarget = {
    visibilityState: 'visible',
    documentElement: { classList: { toggle: () => {} } },
  }
  const context = vm.createContext({
    window: windowTarget, document: documentTarget, navigator: {}, console, Event, URL,
    CustomEvent: class extends Event {
      constructor(type, options) { super(type); this.detail = options?.detail }
    },
    ...globals,
  })
  const modules = {
    vue: { ...vue, onMounted: () => {}, onBeforeUnmount: () => {} },
    'vue-router': { RouterView: {}, useRoute: () => ({ query: {}, meta: {}, fullPath: '/poruke' }), useRouter: () => ({ replace: async () => {} }) },
    'vue-i18n': { useI18n: () => ({ locale: vue.ref('bs'), t: (key) => key }) },
    '../composables/useCurrentUser': { currentUser, loadCurrentUser: async () => currentUser.value },
    './composables/useCurrentUser': { currentUser, loadCurrentUser: async () => currentUser.value, setCurrentUser: (user) => { currentUser.value = user } },
    './composables/useUnreadMessages': { unreadMessageCount },
    '../../composables/useUnreadMessages': { unreadMessageCount },
    '../../composables/useMobileAccountSidebar': { mobileAccountSidebarOpen: vue.ref(false) },
    ...extraModules,
  }
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const script = compileScript(descriptor, { id: 'wave-test' })
  const module = new vm.SourceTextModule(script.content, {
    context, initializeImportMeta: (meta) => { meta.env = { DEV: true } },
  })
  await module.link((specifier, referencingModule) => {
    const requested = referencingModule.dependencySpecifiers.includes(specifier)
    assert.ok(requested)
    const values = modules[specifier]
    if (values) {
      return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value)
      }, { context })
    }
    // Components, icons, and formatting are unrelated to the inbox state tests.
    const importedNames = [...script.content.matchAll(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"]/g)]
      .filter((match) => match[2] === specifier)
      .flatMap((match) => match[1].split(',').map((name) => name.trim().split(/\s+as\s+/)[0]))
    return new vm.SyntheticModule(['default', ...importedNames], function () {
      this.setExport('default', {})
      for (const name of importedNames) this.setExport(name, () => {})
    }, { context })
  })
  await module.evaluate()
  const state = module.namespace.default.setup(props, { expose: () => {} })
  return { state, currentUser, unreadMessageCount, windowTarget, documentTarget }
}

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
  await module.link((specifier) => {
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
