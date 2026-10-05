import assert from 'node:assert/strict'
import { test } from 'node:test'
import { startInboxSync } from '../src/lib/inboxSync.js'

const settle = () => new Promise((resolve) => setImmediate(resolve))

function environment() {
  const windowTarget = new EventTarget()
  const documentTarget = new EventTarget()
  const serviceWorker = new EventTarget()
  documentTarget.visibilityState = 'visible'
  windowTarget.navigator = { onLine: true, serviceWorker }
  windowTarget.setInterval = () => { throw new Error('Inbox updates must not poll') }
  return { windowTarget, documentTarget, serviceWorker }
}

test('starting synchronization does not poll or fetch the inbox', async () => {
  const env = environment()
  let calls = 0
  const sync = startInboxSync(() => { calls += 1 }, env)
  await settle()
  assert.equal(calls, 0)
  await sync.refresh()
  assert.equal(calls, 1)
  sync.stop()
})

test('hidden or offline pages do not fetch and mark an open conversation read', async () => {
  const env = environment()
  let calls = 0
  const sync = startInboxSync(() => { calls += 1 }, env)
  env.documentTarget.visibilityState = 'hidden'
  env.windowTarget.dispatchEvent(new Event('focus'))
  await settle()
  assert.equal(calls, 0)
  env.documentTarget.visibilityState = 'visible'
  env.windowTarget.navigator.onLine = false
  await sync.refresh()
  assert.equal(calls, 0)
  env.windowTarget.navigator.onLine = true
  env.windowTarget.dispatchEvent(new Event('online'))
  await settle()
  assert.equal(calls, 1)
  sync.stop()
})

test('returning to a visible page catches up immediately', async () => {
  const env = environment()
  let calls = 0
  const sync = startInboxSync(() => { calls += 1 }, env)
  env.documentTarget.dispatchEvent(new Event('visibilitychange'))
  await settle()
  assert.equal(calls, 1)
  env.windowTarget.dispatchEvent(new Event('focus'))
  await settle()
  assert.equal(calls, 2)
  sync.stop()
})

test('a service worker push refreshes the page without depending on an OS banner', async () => {
  const env = environment()
  let calls = 0
  const sync = startInboxSync(() => { calls += 1 }, env)
  env.serviceWorker.dispatchEvent(new MessageEvent('message', { data: { type: 'OTHER' } }))
  await settle()
  assert.equal(calls, 0)
  env.serviceWorker.dispatchEvent(new MessageEvent('message', { data: { type: 'WAVE_PUSH_RECEIVED' } }))
  await settle()
  assert.equal(calls, 1)
  sync.stop()
})

test('updates during an in-flight refresh coalesce into one follow-up', async () => {
  const env = environment()
  const resolvers = []
  const sync = startInboxSync(() => new Promise((resolve) => resolvers.push(resolve)), env)
  const first = sync.refresh()
  await sync.refresh()
  await sync.refresh()
  assert.equal(resolvers.length, 1)
  resolvers[0]()
  await first
  assert.equal(resolvers.length, 2)
  resolvers[1]()
  await settle()
  sync.stop()
})

test('cleanup removes listeners and a queued follow-up', async () => {
  const env = environment()
  let calls = 0
  let resolveRefresh
  const sync = startInboxSync(() => {
    calls += 1
    return new Promise((resolve) => { resolveRefresh = resolve })
  }, env)
  const pending = sync.refresh()
  await sync.refresh()
  sync.stop()
  resolveRefresh()
  await pending
  env.windowTarget.dispatchEvent(new Event('focus'))
  env.documentTarget.dispatchEvent(new Event('visibilitychange'))
  env.serviceWorker.dispatchEvent(new MessageEvent('message', { data: { type: 'WAVE_PUSH_RECEIVED' } }))
  await settle()
  assert.equal(calls, 1)
})

test('a failed refresh does not prevent later recovery', async () => {
  const env = environment()
  const errors = []
  let calls = 0
  const sync = startInboxSync(() => {
    calls += 1
    if (calls === 1) throw new Error('offline')
  }, { ...env, onError: (cause) => errors.push(cause.message) })
  await sync.refresh()
  await sync.refresh()
  assert.deepEqual(errors, ['offline'])
  assert.equal(calls, 2)
  sync.stop()
})
