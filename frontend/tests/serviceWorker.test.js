import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import { updateAppBadge } from '../src/lib/appBadge.js'
import { MessageChannel } from 'node:worker_threads'

async function worker({ windows = [], navigator = {} } = {}) {
  const handlers = new Map()
  const opened = []
  const displayed = []
  const self = {
    __WB_MANIFEST: [], navigator, location: { origin: 'https://wave.example.test' },
    addEventListener: (type, callback) => handlers.set(type, callback),
    registration: { showNotification: async (...args) => { displayed.push(args) } },
    clients: { matchAll: async () => windows, openWindow: async (url) => { opened.push(url); return { url } } },
  }
  const context = vm.createContext({ self, URL, MessageChannel, setTimeout, clearTimeout })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/sw.js', import.meta.url), 'utf8'), { context })
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
  const click = async (url) => {
    let completion
    let closed = false
    handlers.get('notificationclick')({
      notification: { data: { url }, close: () => { closed = true } },
      waitUntil: (promise) => { completion = promise },
    })
    await completion
    assert.equal(closed, true)
  }
  const push = async (payload) => {
    let completion
    handlers.get('push')({ data: { json: () => payload }, waitUntil: (promise) => { completion = promise } })
    await completion
  }
  return { opened, displayed, click, push }
}

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
  assert.deepEqual(opened, ['https://wave.example.test/messages?conversation=42'])
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
  const { click, opened } = await worker()
  await click('/messages?conversation=42')
  await click('https://other.example.test/messages?conversation=42')
  await click('http://[')
  assert.deepEqual(opened, [
    'https://wave.example.test/messages?conversation=42',
    'https://wave.example.test/', 'https://wave.example.test/',
  ])
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
