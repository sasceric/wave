import assert from 'node:assert/strict'
import { test } from 'node:test'
import { showStartupLoader } from '../src/lib/startupLoader.js'

function fixture(ready, inert = false) {
  let timeout
  let cleared = false
  let removed = 0
  const classes = new Set()
  const root = { inert }
  const label = { textContent: '' }
  const loader = { setAttribute: () => {}, remove: () => { removed += 1 } }
  const elements = { app: root, 'wave-startup': loader, 'wave-startup-label': label }
  const finish = showStartupLoader({
    ready,
    label: 'Loading…',
    document: {
      getElementById: (id) => elements[id],
      documentElement: { classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) } },
    },
    timers: {
      setTimeout: (callback, delay) => { assert.equal(delay, 10_000); timeout = callback; return 1 },
      clearTimeout: (id) => { assert.equal(id, 1); cleared = true },
    },
  })
  return { root, label, classes, finish, expire: () => timeout(), removed: () => removed, cleared: () => cleared }
}

test('startup releases the screen only after both the route and session settle', async () => {
  let finishRoute
  let finishSession
  const ready = Promise.allSettled([
    new Promise((resolve) => { finishRoute = resolve }),
    new Promise((resolve) => { finishSession = resolve }),
  ])
  const ui = fixture(ready)
  assert.equal(ui.root.inert, true)
  assert.equal(ui.label.textContent, 'Loading…')
  assert.equal(ui.classes.has('app-starting'), true)
  finishRoute()
  await Promise.resolve()
  assert.equal(ui.removed(), 0)
  finishSession()
  await ready
  assert.equal(ui.removed(), 1)
  assert.equal(ui.root.inert, false)
  assert.equal(ui.classes.size, 0)
  assert.equal(ui.cleared(), true)
})

test('a failed startup releases the screen and preserves an existing inert state', async () => {
  const ready = Promise.reject(new Error('Offline'))
  const ui = fixture(ready, true)
  await ready.catch(() => {})
  assert.equal(ui.removed(), 1)
  assert.equal(ui.root.inert, true)
})

test('a stalled startup times out and a late session response cannot remove the loader twice', async () => {
  let resolve
  const ready = new Promise((done) => { resolve = done })
  const ui = fixture(ready)
  ui.expire()
  assert.equal(ui.removed(), 1)
  assert.equal(ui.root.inert, false)
  assert.equal(ui.classes.size, 0)
  resolve()
  await ready
  ui.finish()
  assert.equal(ui.removed(), 1)
})

test('startup is a no-op when the document no longer contains the splash', () => {
  assert.equal(showStartupLoader({ document: { getElementById: () => null } }), undefined)
})
