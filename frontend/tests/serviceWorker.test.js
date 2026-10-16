import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import { updateAppBadge } from '../src/lib/appBadge.js'
import { MessageChannel } from 'node:worker_threads'

async function worker({ windows = [], navigator = {}, session = { id: 1 }, sessionError = false, sessionResponse = null } = {}) {
  const handlers = new Map()
  const opened = []
  const navigated = []
  const displayed = []
  const self = {
    __WB_MANIFEST: [], navigator, location: { origin: 'https://wave.example.test' },
    addEventListener: (type, callback) => handlers.set(type, callback),
    registration: { showNotification: async (...args) => { displayed.push(args) } },
    clients: { matchAll: async () => windows, openWindow: async (url) => { opened.push(url); return { url, navigate: async target => { navigated.push(target); return {} } } } },
  }
  const sessionReads = []
  const fetch = async (url, options) => {
    sessionReads.push({ url, options })
    if (sessionError) throw new Error('Offline')
    if (sessionResponse) await sessionResponse
    return { ok: true, json: async () => ({ data: session }) }
  }
  const context = vm.createContext({ self, URL, MessageChannel, setTimeout, clearTimeout, fetch, AbortSignal })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/sw.js', import.meta.url), 'utf8'), {
    context,
    initializeImportMeta(meta) { meta.env = { VITE_WAVE_BUILD_ID: 'test-build' } },
  })
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
  const click = async (url, userId = 1) => {
    let completion
    let closed = false
    handlers.get('notificationclick')({
      notification: { data: { url, userId }, close: () => { closed = true } },
      waitUntil: (promise) => { completion = promise },
    })
    await completion
    assert.equal(closed, true)
  }
  const push = async (payload, legacy = false) => {
    let completion
    handlers.get('push')({ data: { json: () => legacy ? payload : { userId: 1, ...payload } }, waitUntil: (promise) => { completion = promise } })
    await completion
  }
  return { opened, navigated, displayed, click, push, sessionReads, message: event => handlers.get('message')(event) }
}

test('the worker identifies its build over the requested channel without reading private account data', async () => {
  const { message, sessionReads } = await worker()
  const responses = []
  message({ data: { type: 'WAVE_GET_BUILD' }, ports: [{ postMessage: value => responses.push(value) }] })
  assert.equal(responses[0].type, 'WAVE_BUILD_ID')
  assert.equal(responses[0].buildId, 'test-build')
  assert.deepEqual(sessionReads, [])
  message({ data: { type: 'WAVE_GET_BUILD' } })
})

test('push click focuses before navigating and preserves the intended conversation', async () => {
  const actions = []
  const focusedClient = { navigate: async (url) => { actions.push(url); return {} } }
  const oldClient = {
    focus: async () => { actions.push('focus'); return focusedClient },
    navigate: () => { throw new Error('The replaced client must not be navigated') },
  }
  const { click, opened } = await worker({ windows: [oldClient] })
  await click('/messages?conversation=42')
  assert.deepEqual(actions, ['focus', 'https://wave.example.test/messages?conversation=42'])
  assert.equal(opened.length, 0)
})

test('a dead tab or null navigation cannot swallow a notification click', async () => {
  const dead = { focus: async () => { throw new Error('closed') }, navigate: async () => null }
  const other = { focus: async () => other, navigate: async () => null }
  const { click, opened } = await worker({ windows: [dead, other] })
  await click('/messages?conversation=42')
  assert.deepEqual(opened, ['https://wave.example.test/'])
})

test('a resumed installed app routes the clicked chat directly without a document reload', async () => {
  const messages = []
  const client = {
    focus: async () => client,
    navigate: async () => { throw new Error('A responsive app should use Vue Router') },
    postMessage: (message, ports) => {
      messages.push(message)
      ports[0].postMessage({ type: 'WAVE_NOTIFICATION_OPENED' })
    },
  }
  const { click, opened } = await worker({ windows: [client] })
  await click('/messages?conversation=42')
  assert.equal(messages[0].url, 'https://wave.example.test/messages?conversation=42')
  assert.equal(opened.length, 0)
})

test('an installed app can route the chat even when iOS rejects focus during resume', async () => {
  let requestedUrl
  const client = {
    focus: async () => { throw new Error('Scene is resuming') },
    navigate: async () => { throw new Error('Not needed after routing acknowledgement') },
    postMessage: (message, ports) => {
      requestedUrl = message.url
      ports[0].postMessage({ type: 'WAVE_NOTIFICATION_OPENED' })
    },
  }
  const { click, opened } = await worker({ windows: [client] })
  await click('/messages?conversation=42')
  assert.equal(requestedUrl, 'https://wave.example.test/messages?conversation=42')
  assert.equal(opened.length, 0)
})

test('a cold app opens the push target and unsafe or malformed targets stay on Wave', async () => {
  const { click, opened, navigated } = await worker()
  await click('/messages?conversation=42')
  await click('https://other.example.test/messages?conversation=42')
  await click('http://[')
  assert.deepEqual(opened, Array(3).fill('https://wave.example.test/'))
  assert.deepEqual(navigated, ['https://wave.example.test/messages?conversation=42', 'https://wave.example.test/', 'https://wave.example.test/'])
})

test('background push sets the authoritative app badge and zero clears it', async () => {
  const badges = []
  const { push, displayed } = await worker({ navigator: {
    setAppBadge: async (count) => { badges.push(count) }, clearAppBadge: async () => { badges.push(0) },
  } })
  await push({ title: 'Wave', badgeCount: 5 })
  await push({ title: 'Wave', badgeCount: 0 })
  await push({ title: 'An older push without a badge count' })
  assert.deepEqual(badges, [5, 0])
  assert.equal(displayed.length, 3)
})

test('an unsupported or denied badge API does not stop visible push delivery', async () => {
  for (const navigator of [{}, { setAppBadge: async () => { throw new Error('denied') } }]) {
    const { push, displayed } = await worker({ navigator })
    await push({ title: 'Wave', badgeCount: 5 })
    assert.equal(displayed.length, 1)
  }
})

test('invalid badge counts are ignored and zero works without clearAppBadge', async () => {
  const badges = []
  const navigator = { setAppBadge: async (count) => { badges.push(count) } }
  for (const count of [-1, NaN, 1.5, '4', undefined, Infinity]) await updateAppBadge(count, navigator)
  await updateAppBadge(0, navigator)
  assert.deepEqual(badges, [0])
})


test('a push for the previous account never displays content, updates badges, or signals other windows', async () => {
  const signals = []
  const badges = []
  const { push, displayed, sessionReads } = await worker({
    session: { id: 2 }, windows: [{ postMessage: message => signals.push(message) }],
    navigator: { setAppBadge: async count => badges.push(count) },
  })
  await push({ userId: 1, title: 'Private sender', body: 'Private message', badgeCount: 3 })
  assert.equal(displayed.length, 0)
  assert.equal(signals.length, 0)
  assert.equal(badges.length, 0)
  assert.equal(sessionReads[0].options.cache, 'no-store')
  assert.equal(sessionReads[0].options.credentials, 'same-origin')
})

test('logged-out, disabled, legacy and unverifiable pushes fail closed', async () => {
  for (const options of [{ session: null }, { session: { id: 1, notificationsEnabled: false } }, { sessionError: true }]) {
    const { push, displayed } = await worker(options)
    await push({ title: 'Private' })
    assert.equal(displayed.length, 0)
  }
  const { push, displayed } = await worker()
  await push({ title: 'Legacy without recipient' }, true)
  assert.equal(displayed.length, 0)
})

test('a previously displayed notification cannot route a different account into its private chat', async () => {
  const { click, opened } = await worker({ session: { id: 2 } })
  await click('/messages?conversation=42', 1)
  assert.deepEqual(opened, ['https://wave.example.test/'])
})


test('session verification cannot delay the user-activation focus or safe cold app opening', async () => {
  for (const cold of [false, true]) {
    let finish
    const actions = []
    const client = { focus: async () => { actions.push('focus'); return client }, navigate: async url => { actions.push(url); return {} } }
    const { click, opened } = await worker({ windows: cold ? [] : [client], sessionResponse: new Promise(resolve => { finish = resolve }) })
    const pending = click('/messages?conversation=42')
    await new Promise(resolve => setImmediate(resolve))
    if (cold) assert.deepEqual(opened, ['https://wave.example.test/'])
    else assert.deepEqual(actions, ['focus'])
    finish()
    await pending
  }
})
