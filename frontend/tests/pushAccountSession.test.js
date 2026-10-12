import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import * as vue from 'vue'
import { setupView } from './setupView.js'

function browserPush(subscription) {
  return {
    window: { Notification: {}, PushManager: {}, matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }) },
    navigator: { serviceWorker: { ready: Promise.resolve({ pushManager: { getSubscription: async () => subscription } }) } },
  }
}

const device = { endpoint: 'https://web.push.apple.com/shared-device', toJSON: () => ({ keys: { p256dh: 'key', auth: 'token' } }) }

test('an existing browser subscription is registered to the current account on every login', async () => {
  const requests = []
  const { state, currentUser } = await setupView('../src/App.vue', {
    vue: { ...vue, watch: () => {}, onMounted: () => {}, onBeforeUnmount: () => {} },
    './lib/api': { apiGet: async () => ({ data: { enabled: true, publicKey: 'key' } }), apiRequest: async (path, options) => requests.push({ path, options }) },
  }, {}, browserPush(device))
  await state.loadPushSettings()
  assert.equal(requests[0].options.body.userId, 1)
  assert.equal(state.pushSubscribed.value, true)
  currentUser.value = { id: 2 }
  await state.loadPushSettings()
  assert.equal(requests[1].options.body.userId, 2)
  assert.equal(requests[1].options.body.endpoint, device.endpoint)
})

test('a push settings request started for the old account cannot bind the device after a switch', async () => {
  const requests = []
  let finish
  let first = true
  const { state, currentUser } = await setupView('../src/App.vue', {
    vue: { ...vue, watch: () => {}, onMounted: () => {}, onBeforeUnmount: () => {} },
    './lib/api': {
      apiGet: async () => { if (first) { first = false; return new Promise(resolve => { finish = resolve }) } return { data: { enabled: true, publicKey: 'key' } } },
      apiRequest: async (path, options) => requests.push({ path, options }),
    },
  }, {}, browserPush(device))
  const old = state.loadPushSettings()
  currentUser.value = { id: 2 }
  await state.loadPushSettings()
  finish({ data: { enabled: true, publicKey: 'key' } })
  await old
  assert.equal(requests.length, 1)
  assert.equal(requests[0].options.body.userId, 2)
})

test('logout sends this device endpoint before invalidating the session and clears visible notifications afterwards', async () => {
  const events = []
  const context = vm.createContext({ navigator: { serviceWorker: { getRegistration: async () => ({
    pushManager: { getSubscription: async () => device },
    getNotifications: async () => [{ close: () => events.push('closed') }],
  }) } } })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/pushSession.js', import.meta.url), 'utf8'), { context })
  await module.link(() => new vm.SyntheticModule(['apiRequest'], function () {
    this.setExport('apiRequest', async (path, options) => { events.push({ path, options }) })
  }, { context }))
  await module.evaluate()
  await module.namespace.logoutAccount()
  assert.equal(events[0].path, '/auth/logout')
  assert.equal(events[0].options.body.pushEndpoint, device.endpoint)
  assert.equal(events[1], 'closed')
})

test('a response authenticated as another account is never rendered under the old account', async () => {
  const { state, currentUser } = await setupView('../src/App.vue', {
    vue: { ...vue, watch: () => {}, onMounted: () => {}, onBeforeUnmount: () => {} },
    './lib/api': { apiGet: async () => ({ userId: 2, data: [{ id: 55, title: 'Private' }], unreadCount: 1 }), apiRequest: async () => {} },
  })
  await state.loadNotifications()
  assert.equal(state.notifications.value.length, 0)
  assert.equal(currentUser.value, null)
})
