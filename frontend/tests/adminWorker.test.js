import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import test from 'node:test'

async function settle() {
  await new Promise((resolve) => setImmediate(resolve))
}

async function worker({ locks, enabled = true, consume = async () => ({ data: { handled: 0 } }) } = {}) {
  const document = new EventTarget()
  document.hidden = false
  const user = { value: { id: 1, isAdmin: true } }
  const route = { meta: { adminSection: true } }
  const requests = []
  const timers = new Map()
  let timerId = 0
  let restart
  let unmount
  const modules = {
    vue: {
      watch: (_source, callback) => { restart = callback; callback() },
      onBeforeUnmount: (callback) => { unmount = callback },
    },
    '../lib/api': {
      apiGet: async (path) => { requests.push(path); return { data: { enabled, idleIntervalMs: 2000 } } },
      apiRequest: async (path) => { requests.push(path); return consume() },
    },
  }
  const context = vm.createContext({
    document, navigator: { locks },
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id },
    clearTimeout: (id) => timers.delete(id),
  })
  const source = await readFile(new URL('../src/composables/useAdminWorker.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link((specifier) => new vm.SyntheticModule(Object.keys(modules[specifier]), function () {
    for (const [name, value] of Object.entries(modules[specifier])) this.setExport(name, value)
  }, { context }))
  await module.evaluate()
  module.namespace.useAdminWorker(user, route)
  await settle()
  return { document, user, route, requests, timers, restart: () => restart(), unmount: () => unmount() }
}

function sharedLocks() {
  let held = false
  return {
    request: async (_name, _options, callback) => {
      if (held) return callback(null)
      held = true
      try { return await callback({ name: 'wave-admin-worker' }) } finally { held = false }
    },
  }
}

test('production-disabled worker never consumes jobs', async () => {
  const app = await worker({ enabled: false })
  assert.deepEqual(app.requests, ['/admin/tools/worker/config'])
  assert.equal(app.timers.size, 0)
  app.unmount()
})

test('one visible admin tab holds leadership until hidden, allowing another tab to take over', async () => {
  const locks = sharedLocks()
  const first = await worker({ locks })
  const second = await worker({ locks })
  assert.equal(first.requests.filter((path) => path.endsWith('/consume')).length, 1)
  assert.equal(second.requests.filter((path) => path.endsWith('/consume')).length, 0)
  first.document.hidden = true
  first.document.dispatchEvent(new Event('visibilitychange'))
  await settle()
  assert.equal(first.timers.size, 0)
  const retry = [...second.timers.values()][0]
  retry.callback()
  await settle()
  assert.equal(second.requests.filter((path) => path.endsWith('/consume')).length, 1)
  first.unmount()
  second.unmount()
})

test('logout cancels timers and a late consume response cannot restart the worker', async () => {
  let finish
  const app = await worker({ consume: () => new Promise((resolve) => { finish = resolve }) })
  app.user.value = null
  await app.restart()
  finish({ data: { handled: 3 } })
  await settle()
  assert.equal(app.timers.size, 0)
  assert.equal(app.requests.length, 2)
  app.unmount()
})

test('transient failures back off and forbidden responses stop consumption', async () => {
  const transient = await worker({ consume: async () => { throw Object.assign(new Error('temporary'), { status: 503 }) } })
  assert.equal([...transient.timers.values()][0].delay, 10000)
  transient.unmount()
  const forbidden = await worker({ consume: async () => { throw Object.assign(new Error('forbidden'), { status: 403 }) } })
  assert.equal(forbidden.timers.size, 0)
  forbidden.unmount()
})
