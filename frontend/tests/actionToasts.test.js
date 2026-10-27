import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import { createToastStore } from '../src/lib/toasts.js'

function clock() {
  let time = 0
  let next = 0
  const pending = new Map()
  const store = createToastStore({ now: () => time, schedule: (fn, ms) => { const id = ++next; pending.set(id, { at: time + ms, fn }); return id }, cancel: id => pending.delete(id) })
  function advance(ms) {
    time += ms
    for (const [id, task] of pending) if (task.at <= time) { pending.delete(id); task.fn() }
  }
  return { store, advance, pending }
}

test('action toasts deduplicate, bound the stack and expire success/errors independently', () => {
  const { store, advance, pending } = clock()
  store.notify('Saved')
  store.notify('Saved')
  assert.equal(store.messages.value.length, 1)
  assert.equal(pending.size, 1)
  store.notify('Overlap', 'error')
  advance(5000)
  assert.deepEqual(store.messages.value.map(item => item.message), ['Overlap'])
  advance(5000)
  assert.equal(store.messages.value.length, 0)
  for (let i = 0; i < 5; i++) store.notify(`Action ${i}`)
  assert.equal(store.messages.value.length, 3)
  assert.equal(pending.size, 3)
  store.clear()
  assert.equal(pending.size, 0)
})

test('hover, keyboard focus and hidden tabs pause dismissal until every pause is released', () => {
  const { store, advance } = clock()
  const id = store.notify('Read this', 'error')
  advance(2000)
  store.pause(id, 'pointer')
  store.pause(id, 'focus')
  advance(20000)
  store.resume(id, 'pointer')
  advance(20000)
  assert.equal(store.messages.value.length, 1)
  store.resume(id, 'focus')
  advance(7999)
  assert.equal(store.messages.value.length, 1)
  advance(1)
  assert.equal(store.messages.value.length, 0)
})

async function feedback() {
  const calls = []
  const context = vm.createContext({})
  const source = await readFile(new URL('../src/lib/actionFeedback.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(specifier => specifier.includes('toasts')
    ? new vm.SyntheticModule(['actionToasts'], function () { this.setExport('actionToasts', { notify: (...args) => calls.push(args) }) }, { context })
    : new vm.SyntheticModule(['default'], function () { this.setExport('default', { global: { t: key => key } }) }, { context }))
  await module.evaluate()
  return { ...module.namespace, calls }
}

test('all normal API mutations get feedback while background acknowledgements remain quiet', async () => {
  const { actionFeedback, calls, hasActionFeedback } = await feedback()
  for (const [path, method] of [['/me/profile', 'PUT'], ['/admin/creators/bulk-delete', 'DELETE'], ['/media', 'POST'], ['/me/notifications/read-all', 'POST']]) {
    assert.equal(hasActionFeedback(path, method), true)
    actionFeedback(path, method, undefined, {})
  }
  assert.equal(calls.length, 4)
  for (const [path, method] of [['/creators', 'GET'], ['/admin/tools/worker/consume', 'POST'], ['/creators/example/view', 'POST'], ['/me/push-subscriptions', 'POST'], ['/me/conversations/1/read', 'POST']]) {
    assert.equal(hasActionFeedback(path, method), false)
    actionFeedback(path, method, undefined, {})
  }
  assert.equal(calls.length, 4)
  actionFeedback('/me/profile', 'PUT', false, {})
  assert.equal(calls.length, 4)
})

test('server placement errors, custom save messages and aborts produce exactly the intended feedback', async () => {
  const { actionFeedback, calls } = await feedback()
  const error = new Error('Mjesta se preklapaju. Odaberi slobodno mjesto ili deaktiviraj drugi oglas.')
  actionFeedback('/admin/listing-ads/campaigns/3', 'PUT', { success: 'Oglas je sačuvan.' }, null, error)
  actionFeedback('/admin/listing-ads/campaigns/3', 'PUT', { success: 'Oglas je sačuvan.' }, {})
  actionFeedback('/me/profile', 'PUT', undefined, null, { name: 'AbortError' })
  assert.deepEqual(calls, [[error.message, 'error'], ['Oglas je sačuvan.', 'success']])
})
