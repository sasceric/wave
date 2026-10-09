import assert from 'node:assert/strict'
import test from 'node:test'
import { isInstalledApp, startPullToRefresh } from '../src/lib/pullToRefresh.js'

function environment() {
  const listeners = new Map()
  const classes = new Set()
  const body = { style: { overflowY: 'visible' } }
  const target = { parentElement: body, style: {}, closest: () => null }
  const documentTarget = {
    body,
    visibilityState: 'visible',
    activeElement: null,
    scrollingElement: { scrollTop: 0 },
    querySelector: () => null,
    documentElement: { classList: {
      contains: name => classes.has(name),
      add: name => classes.add(name),
      remove: name => classes.delete(name),
    } },
    addEventListener: (type, handler) => listeners.set(type, handler),
    removeEventListener: type => listeners.delete(type),
  }
  const windowTarget = {
    navigator: { onLine: true },
    scrollY: 0,
    getComputedStyle: element => element.style,
  }
  const changes = []
  let refreshes = 0
  const stop = startPullToRefresh({
    windowTarget, documentTarget,
    onChange: (progress, busy) => changes.push({ progress, busy }),
    onRefresh: () => { refreshes += 1 },
  })
  function event(type, x = 0, y = 0, options = {}) {
    const value = {
      target,
      touches: type === 'touchend' ? [] : [{ clientX: x, clientY: y }],
      cancelable: true,
      defaultPrevented: false,
      preventDefault() { this.defaultPrevented = true },
      ...options,
    }
    return { value, done: listeners.get(type)?.(value) }
  }
  async function pull(options = {}) {
    event('touchstart', 0, 20, options)
    event('touchmove', 0, 140, options)
    await event('touchend').done
  }
  return { listeners, classes, windowTarget, documentTarget, target, changes, stop, event, pull, refreshes: () => refreshes }
}

test('installed-mode detection supports iOS and standalone display, but excludes browser tabs', () => {
  assert.equal(isInstalledApp({ navigator: { standalone: true }, matchMedia: () => ({ matches: false }) }), true)
  assert.equal(isInstalledApp({ navigator: {}, matchMedia: () => ({ matches: true }) }), true)
  assert.equal(isInstalledApp({ navigator: {}, matchMedia: () => ({ matches: false }) }), false)
})

test('a deliberate downward pull at the top refreshes only on release', async () => {
  const env = environment()
  env.event('touchstart', 10, 20)
  const move = env.event('touchmove', 10, 140)
  assert.equal(move.value.defaultPrevented, true)
  assert.equal(env.refreshes(), 0)
  assert.ok(env.changes.at(-1).progress >= 1)
  await env.event('touchend').done
  assert.equal(env.refreshes(), 1)
  assert.ok(env.changes.some(change => change.busy))
})

test('short pulls, upward scrolling, horizontal gestures, and cancelled touches never refresh', async () => {
  for (const [x, y, cancel] of [[0, 60, false], [0, 0, false], [120, 40, false], [0, 140, true]]) {
    const env = environment()
    env.event('touchstart', 0, 20)
    const move = env.event('touchmove', x, y)
    if (cancel) env.event('touchcancel')
    await env.event('touchend').done
    assert.equal(env.refreshes(), 0)
    if (y <= 20 || x > y) assert.equal(move.value.defaultPrevented, false)
  }
})

test('pinch zoom and non-cancelable or previously handled gestures are left alone', async () => {
  for (const options of [
    { touches: [{ clientX: 0, clientY: 140 }, { clientX: 30, clientY: 140 }] },
    { cancelable: false },
    { defaultPrevented: true },
  ]) {
    const env = environment()
    env.event('touchstart', 0, 20)
    env.event('touchmove', 0, 140, options)
    await env.event('touchend').done
    assert.equal(env.refreshes(), 0)
  }
})

test('pages below the top, offline pages, hidden pages and open dialogs do not refresh', async () => {
  for (const block of [
    env => { env.windowTarget.scrollY = 10 },
    env => { env.documentTarget.scrollingElement.scrollTop = 10 },
    env => { env.windowTarget.navigator.onLine = false },
    env => { env.documentTarget.visibilityState = 'hidden' },
    env => { env.documentTarget.querySelector = () => ({}) },
    env => { env.documentTarget.body.style.overflowY = 'hidden' },
    env => { env.documentTarget.activeElement = { matches: () => true } },
  ]) {
    const env = environment()
    block(env)
    await env.pull()
    assert.equal(env.refreshes(), 0)
  }
})

test('carousels and nested conversation histories keep their own scrolling gestures', async () => {
  for (const vertical of [true, false]) {
    const env = environment()
    Object.assign(env.target, {
      style: { overflowX: vertical ? 'visible' : 'auto', overflowY: vertical ? 'auto' : 'visible' },
      scrollHeight: vertical ? 300 : 100, clientHeight: 100,
      scrollWidth: vertical ? 100 : 300, clientWidth: 100,
    })
    env.event('touchstart', 0, 20)
    assert.equal(env.event('touchmove', 0, 140).value.defaultPrevented, false)
    await env.event('touchend').done
    assert.equal(env.refreshes(), 0)
  }
})

test('editing a form, choosing an upload or writing a draft blocks refresh until its view is removed', async () => {
  for (const type of ['input', 'change']) {
    const env = environment()
    const form = { isConnected: true }
    env.event(type, 0, 0, { target: { closest: () => form } })
    await env.pull()
    assert.equal(env.refreshes(), 0)
    form.isConnected = false
    await env.pull()
    assert.equal(env.refreshes(), 1)
  }
})

test('going offline before release cancels a ready pull', async () => {
  const env = environment()
  env.event('touchstart', 0, 20)
  env.event('touchmove', 0, 140)
  env.windowTarget.navigator.onLine = false
  await env.event('touchend').done
  assert.equal(env.refreshes(), 0)
})

test('custom select clicks and dropped uploads also protect their form', async () => {
  for (const type of ['click', 'drop']) {
    const env = environment()
    const form = { isConnected: true }
    env.event(type, 0, 0, { type, target: { closest: selector => selector.startsWith('button') ? {} : form } })
    await env.pull()
    assert.equal(env.refreshes(), 0)
  }
})

test('another pull cannot refresh again while a refresh is still pending', async () => {
  const env = environment()
  env.stop()
  let resolveRefresh
  let calls = 0
  const stop = startPullToRefresh({
    windowTarget: env.windowTarget,
    documentTarget: env.documentTarget,
    onChange: () => {},
    onRefresh: () => { calls += 1; return new Promise(resolve => { resolveRefresh = resolve }) },
  })
  env.event('touchstart', 0, 20)
  env.event('touchmove', 0, 140)
  const release = env.event('touchend').done
  await env.pull()
  assert.equal(calls, 1)
  resolveRefresh()
  await release
  stop()
})

test('stopping removes touch and edit listeners and restores overscroll behavior', async () => {
  const env = environment()
  assert.equal(env.classes.has('pwa-pull-to-refresh'), true)
  env.event('touchstart', 0, 20)
  env.event('touchmove', 0, 140)
  env.stop()
  assert.equal(env.listeners.size, 0)
  assert.equal(env.classes.size, 0)
  assert.equal(env.changes.at(-1).progress, 0)
  await env.pull()
  assert.equal(env.refreshes(), 0)
})
