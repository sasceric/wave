import assert from 'node:assert/strict'
import { test } from 'node:test'
import { MessageChannel } from 'node:worker_threads'
import { startPwaUpdates } from '../src/lib/pwaUpdates.js'

const settle = () => new Promise(resolve => setImmediate(resolve))

function worker(buildId = 'page-build', state = 'activated') {
  const target = new EventTarget()
  target.state = state
  target.messages = []
  target.postMessage = (data, ports) => {
    target.messages.push(data.type)
    if (data.type === 'WAVE_GET_BUILD' && buildId) ports[0].postMessage({ type: 'WAVE_BUILD_ID', buildId })
  }
  target.transition = (value) => {
    target.state = value
    target.dispatchEvent(new Event('statechange'))
  }
  return target
}

function environment(t, { controller = worker(), waiting = null, installing = null, channel = true } = {}) {
  const windowTarget = new EventTarget()
  const documentTarget = new EventTarget()
  const serviceWorker = new EventTarget()
  serviceWorker.controller = controller
  windowTarget.navigator = { serviceWorker, onLine: true }
  windowTarget.MessageChannel = channel ? MessageChannel : null
  let reloads = 0
  let checks = 0
  let options
  let interval
  const clearedIntervals = []
  const available = []
  const updating = []
  const errors = []
  const checkErrors = []
  const registration = { waiting, installing, update: async () => { checks += 1 } }
  documentTarget.visibilityState = 'visible'
  windowTarget.location = { reload: () => { reloads += 1 } }
  windowTarget.setTimeout = setTimeout
  windowTarget.clearTimeout = clearTimeout
  windowTarget.setInterval = (callback) => { interval = callback; return 123 }
  windowTarget.clearInterval = id => clearedIntervals.push(id)
  const sync = startPwaUpdates({
    registerSW: value => { options = value },
    buildId: 'page-build', windowTarget, documentTarget,
    activationTimeout: 40, probeTimeout: 20,
    onAvailable: value => available.push(value),
    onUpdating: value => updating.push(value),
    onError: cause => errors.push(cause.message),
    onCheckError: cause => checkErrors.push(cause.message),
  })
  t.after(() => sync.stop())
  return {
    sync, windowTarget, documentTarget, serviceWorker, registration, options,
    available, updating, errors, checkErrors, clearedIntervals,
    get reloads() { return reloads }, get checks() { return checks },
    register: async () => { options.onRegisteredSW('/sw.js', registration); await sync.check() },
    activate(next) {
      registration.waiting = null
      registration.installing = null
      next.transition('activated')
      serviceWorker.controller = next
      serviceWorker.dispatchEvent(new Event('controllerchange'))
    },
    interval: () => interval(),
  }
}

test('Update waits for the waiting worker to control the page, then reloads exactly once', async (t) => {
  const next = worker('new-build', 'installed')
  const env = environment(t, { waiting: next })
  await env.register()
  const applying = env.sync.apply()
  await env.sync.apply()
  await settle()
  assert.ok(next.messages.includes('SKIP_WAITING'))
  assert.equal(env.reloads, 0)
  next.transition('activated')
  assert.equal(env.reloads, 0, 'activation alone does not guarantee control')
  env.activate(next)
  await applying
  env.options.onNeedReload()
  await env.sync.apply()
  assert.equal(env.reloads, 1)
  assert.deepEqual(env.updating, [true, false])
})

test('a resumed document detects an already activated update without a waiting event', async (t) => {
  const env = environment(t, { controller: worker('new-build') })
  await env.register()
  assert.ok(env.available.length > 0)
  assert.equal(env.reloads, 0, 'discovery must not discard drafts')
  await env.sync.apply()
  assert.equal(env.reloads, 1, 'Update still reloads when another window activated it')
})

test('returning from suspension detects a missed controllerchange and retains the current page until accepted', async (t) => {
  const env = environment(t)
  await env.register()
  assert.deepEqual(env.available, [])
  env.serviceWorker.controller = worker('new-build')
  env.windowTarget.dispatchEvent(new Event('pageshow'))
  await env.sync.check()
  assert.ok(env.available.length > 0)
  assert.equal(env.reloads, 0)
})

test('an update activated in another window prompts instead of forcing a reload', async (t) => {
  const env = environment(t, { channel: false })
  await env.register()
  env.activate(worker('new-build'))
  await settle()
  assert.ok(env.available.length > 0)
  assert.equal(env.reloads, 0)
})

test('first installation and a controller matching the page do not create false update prompts', async (t) => {
  const env = environment(t, { controller: null })
  await env.register()
  env.activate(worker())
  await env.sync.check()
  await settle()
  assert.deepEqual(env.available, [])
  assert.equal(env.reloads, 0)
})

test('Update waits for a downloading worker before requesting activation', async (t) => {
  const next = worker('new-build', 'installing')
  const env = environment(t, { installing: next })
  await env.register()
  const applying = env.sync.apply()
  assert.equal(env.reloads, 0)
  env.registration.installing = null
  env.registration.waiting = next
  next.transition('installed')
  await settle()
  assert.ok(next.messages.includes('SKIP_WAITING'))
  env.activate(next)
  await applying
  assert.equal(env.reloads, 1)
})

test('failed installations and activation timeouts clear the spinner and allow retry', async (t) => {
  const next = worker('new-build', 'installing')
  const env = environment(t, { installing: next })
  await env.register()
  const first = env.sync.apply()
  next.transition('redundant')
  await first
  assert.match(env.errors[0], /installation failed/)
  assert.equal(env.updating.at(-1), false)
  env.registration.installing = null
  env.registration.waiting = worker('new-build', 'installed')
  await env.sync.apply()
  assert.match(env.errors[1], /timed out/)
  assert.equal(env.updating.at(-1), false)
  assert.equal(env.reloads, 0)
  env.registration.waiting = null
  await env.sync.apply()
  assert.equal(env.reloads, 1)
})

test('a failed activation message immediately restores the retry button', async (t) => {
  const next = worker('new-build', 'installed')
  const env = environment(t, { waiting: next })
  await env.register()
  next.postMessage = () => { throw new Error('Worker unavailable') }
  await env.sync.apply()
  assert.deepEqual(env.errors, ['Worker unavailable'])
  assert.deepEqual(env.updating, [true, false])
  assert.equal(env.reloads, 0)
})

test('hidden and offline windows defer checks; overlapping resume events share a check', async (t) => {
  const env = environment(t)
  await env.register()
  const initialChecks = env.checks
  env.documentTarget.visibilityState = 'hidden'
  env.windowTarget.dispatchEvent(new Event('focus'))
  await env.sync.check()
  env.documentTarget.visibilityState = 'visible'
  env.windowTarget.navigator.onLine = false
  await env.sync.check()
  assert.equal(env.checks, initialChecks)
  env.windowTarget.navigator.onLine = true
  env.windowTarget.dispatchEvent(new Event('online'))
  env.documentTarget.dispatchEvent(new Event('visibilitychange'))
  env.windowTarget.dispatchEvent(new Event('focus'))
  env.interval()
  await env.sync.check()
  assert.equal(env.checks, initialChecks + 1)
})

test('older workers without a build handshake still update via the waiting worker', async (t) => {
  const next = worker(null, 'installed')
  const env = environment(t, { controller: worker(null), waiting: next })
  await env.register()
  assert.ok(env.available.length > 0)
  const applying = env.sync.apply()
  env.activate(next)
  await applying
  assert.equal(env.reloads, 1)
})

test('stopping during activation removes listeners, cancels timers and prevents late reloads', async (t) => {
  const next = worker('new-build', 'installed')
  const env = environment(t, { waiting: next })
  await env.register()
  const applying = env.sync.apply()
  env.sync.stop()
  env.activate(next)
  env.windowTarget.dispatchEvent(new Event('pageshow'))
  await applying
  assert.equal(env.reloads, 0)
  assert.deepEqual(env.errors, [])
  assert.ok(env.clearedIntervals.includes(123))
})

test('an update network failure is recoverable and does not sign out or reload the user', async (t) => {
  const env = environment(t)
  await env.register()
  env.registration.update = async () => { throw new Error('offline') }
  await env.sync.apply()
  assert.deepEqual(env.errors, ['offline'])
  assert.equal(env.updating.at(-1), false)
  assert.equal(env.reloads, 0)
  env.registration.update = async () => {}
  await env.sync.apply()
  assert.equal(env.reloads, 1)
})

test('a stalled network update check cannot leave Update spinning indefinitely', async (t) => {
  const env = environment(t)
  await env.register()
  env.registration.update = () => new Promise(() => {})
  await env.sync.apply()
  assert.match(env.errors[0], /check timed out/)
  assert.equal(env.updating.at(-1), false)
  assert.equal(env.reloads, 0)
})
