import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function directory(apiGet, filters = {}) {
  let mounted
  let unmounted
  const locale = vue.ref('bs')
  const context = vm.createContext({ URLSearchParams, Error })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/composables/useInfiniteDirectory.js', import.meta.url), 'utf8'), { context })
  await module.link((specifier) => {
    const exports = specifier === 'vue'
      ? { ...vue, onMounted: (callback) => { mounted = callback }, onBeforeUnmount: (callback) => { unmounted = callback } }
      : specifier === 'vue-i18n'
        ? { useI18n: () => ({ t: (key) => key }) }
        : { apiGet }
    return new vm.SyntheticModule(Object.keys(exports), function () {
      Object.entries(exports).forEach(([name, value]) => this.setExport(name, value))
    }, { context })
  })
  await module.evaluate()
  const scope = vue.effectScope()
  const state = scope.run(() => module.namespace.useInfiniteDirectory('/creators', locale, filters))
  return { state, locale, mounted: () => mounted(), unmount: () => { unmounted(); scope.stop() } }
}

const batch = (from, count) => Array.from({ length: count }, (_, index) => ({ id: from + index }))
const settle = () => new Promise((resolve) => setImmediate(resolve))

test('directories append 30 at a time and stop at the authoritative total without an extra fetch', async () => {
  const requests = []
  const { state, mounted, unmount } = await directory(async (path) => {
    requests.push(path)
    const offset = Number(new URLSearchParams(path.split('?')[1]).get('offset'))
    return { data: batch(offset + 1, Math.min(30, 61 - offset)), meta: { total: 61 } }
  })
  await mounted()
  assert.equal(state.items.value.length, 30)
  assert.equal(state.hasMore.value, true)
  await state.loadMore()
  assert.equal(state.items.value.length, 60)
  await state.loadMore()
  assert.equal(state.items.value.length, 61)
  assert.equal(state.hasMore.value, false)
  await state.loadMore()
  assert.deepEqual(requests, ['/creators?limit=30&offset=0&view=card', '/creators?limit=30&offset=30&view=card', '/creators?limit=30&offset=60&view=card'])
  unmount()
})

test('duplicate intersection events cannot overlap requests, and failed batches retry the same offset', async () => {
  let complete
  const requests = []
  const { state, mounted, unmount } = await directory((path) => {
    requests.push(path)
    if (requests.length === 1) return Promise.resolve({ data: batch(1, 30), meta: { total: 60 } })
    return new Promise((resolve, reject) => { complete = { resolve, reject } })
  })
  await mounted()
  const loading = state.loadMore()
  await state.loadMore()
  assert.equal(requests.length, 2)
  assert.equal(state.items.value.length, 30)
  complete.reject(new Error('Connection lost'))
  await loading
  assert.equal(state.error.value, 'Connection lost')
  assert.equal(state.items.value.length, 30)
  const retry = state.loadMore()
  assert.equal(state.error.value, '')
  assert.equal(requests[2], requests[1])
  complete.resolve({ data: batch(31, 30), meta: { total: 60 } })
  await retry
  assert.equal(state.items.value.length, 60)
  assert.equal(state.hasMore.value, false)
  unmount()
})

test('filter changes discard stale batches and restart at zero; language changes use the new locale', async () => {
  const search = vue.ref('')
  const pending = []
  const { state, locale, mounted, unmount } = await directory((path, options) => new Promise((resolve) => pending.push({ path, options, resolve })), { q: search })
  const initial = mounted()
  pending[0].resolve({ data: batch(1, 30), meta: { total: 60 } })
  await initial
  const oldBatch = state.loadMore()
  search.value = ' Food & travel '
  assert.equal(state.items.value.length, 0)
  assert.equal(pending[2].path, '/creators?limit=30&offset=0&view=card&q=Food+%26+travel')
  pending[2].resolve({ data: [{ id: 100 }], meta: { total: 1 } })
  await settle()
  pending[1].resolve({ data: batch(31, 30), meta: { total: 60 } })
  await oldBatch
  assert.deepEqual(Array.from(state.items.value, (item) => item.id), [100])
  locale.value = 'en'
  assert.equal(pending[3].options.locale, 'en')
  assert.equal(state.items.value.length, 0)
  pending[3].resolve({ data: [{ id: 101 }], meta: { total: 1 } })
  await settle()
  assert.deepEqual(Array.from(state.items.value, (item) => item.id), [101])
  unmount()
})

test('repeated cards advance by the received batch, and an empty batch stops even with an outdated total', async () => {
  const responses = [{ data: batch(1, 30), meta: { total: 100 } }, { data: batch(25, 30), meta: { total: 100 } }, { data: [], meta: { total: 100 } }]
  const paths = []
  const { state, mounted, unmount } = await directory(async (path) => { paths.push(path); return responses.shift() })
  await mounted()
  await state.loadMore()
  assert.equal(state.items.value.length, 54)
  await state.loadMore()
  assert.equal(paths[2], '/creators?limit=30&offset=60&view=card')
  assert.equal(state.hasMore.value, false)
  await state.loadMore()
  assert.equal(paths.length, 3)
  unmount()
})

test('short batches without totals stop, and an unmounted directory ignores pending results', async () => {
  const first = await directory(async () => ({ data: batch(1, 5) }))
  await first.mounted()
  assert.equal(first.state.hasMore.value, false)
  assert.equal(first.state.total.value, 5)
  first.unmount()
  let complete
  const second = await directory(() => new Promise((resolve) => { complete = resolve }))
  const initial = second.mounted()
  second.unmount()
  complete({ data: batch(1, 30), meta: { total: 60 } })
  await initial
  assert.equal(second.state.items.value.length, 0)
})

test('the shared sentinel measures the new end after append and stops observing errors or completion', async () => {
  const props = vue.reactive({ loading: true, hasMore: true, error: '', count: 0 })
  let mounted
  let unmounted
  let observer
  class IntersectionObserver {
    constructor(callback, options) { this.callback = callback; this.options = options; this.observed = false; observer = this }
    observe() { this.observed = true }
    disconnect() { this.observed = false }
  }
  const { state, emitted } = await setupView('../src/components/shared/DirectoryLoadMore.vue', {
    vue: { ...vue, onMounted: (callback) => { mounted = callback }, onBeforeUnmount: (callback) => { unmounted = callback } },
  }, props, { IntersectionObserver })
  state.sentinel.value = {}
  mounted()
  assert.equal(observer.observed, false)
  props.loading = false
  props.count = 30
  await vue.nextTick()
  assert.equal(observer.observed, true)
  assert.equal(observer.options.rootMargin, '200px 0px')
  observer.callback([{ isIntersecting: true }])
  assert.equal(emitted.length, 1)
  props.loading = true
  observer.callback([{ isIntersecting: true }])
  assert.equal(emitted.length, 1)
  await vue.nextTick()
  assert.equal(observer.observed, false)
  props.loading = false
  props.count = 60
  await vue.nextTick()
  // A fresh out-of-view observation must not fetch a third batch.
  observer.callback([{ isIntersecting: false }])
  assert.equal(emitted.length, 1)
  props.error = 'Connection lost'
  await vue.nextTick()
  observer.callback([{ isIntersecting: true }])
  assert.equal(emitted.length, 1)
  assert.equal(observer.observed, false)
  props.error = ''
  props.hasMore = false
  await vue.nextTick()
  observer.callback([{ isIntersecting: true }])
  assert.equal(emitted.length, 1)
  unmounted()
  assert.equal(observer.observed, false)
})
