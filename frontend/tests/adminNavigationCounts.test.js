import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import * as vue from 'vue'

const flush = () => new Promise(resolve => setImmediate(resolve))
async function countsClient() {
  const requests = []
  const route = vue.reactive({ fullPath: '/admin' })
  const window = new EventTarget()
  const context = vm.createContext({ window })
  const source = await readFile(new URL('../src/composables/useAdminNavigationCounts.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  const values = {
    vue: { ...vue, onMounted: callback => callback(), onBeforeUnmount: () => {} },
    'vue-router': { useRoute: () => route },
    '../lib/api': { apiGet: path => new Promise((resolve, reject) => requests.push({ path, resolve, reject })) },
  }
  await module.link(specifier => new vm.SyntheticModule(Object.keys(values[specifier]), function () {
    for (const [key, value] of Object.entries(values[specifier])) this.setExport(key, value)
  }, { context }))
  await module.evaluate()
  return { useCounts: module.namespace.useAdminNavigationCounts, requests, window, route }
}

test('sidebar instances share one admin count request and guest accounts make none', async () => {
  const { useCounts, requests } = await countsClient()
  const user = vue.ref(null)
  const first = useCounts(() => user.value)
  const second = useCounts(() => user.value)
  assert.equal(requests.length, 0)
  user.value = { id: 9, isAdmin: true, emailVerified: true }
  await vue.nextTick()
  assert.equal(requests.length, 1)
  assert.equal(requests[0].path, '/admin/navigation-counts')
  requests[0].resolve({ userId: 9, data: { approvals: 2, reports: 3, tickets: 4 } })
  await flush()
  assert.equal(first.value.reports, 3)
  assert.equal(second.value.tickets, 4)
})

test('late admin count responses cannot leak badges into another account', async () => {
  const { useCounts, requests } = await countsClient()
  const user = vue.ref({ id: 9, isAdmin: true, emailVerified: true })
  const counts = useCounts(() => user.value)
  user.value = { id: 10, isAdmin: false, emailVerified: true }
  await vue.nextTick()
  requests[0].resolve({ userId: 9, data: { approvals: 2, reports: 3, tickets: 4 } })
  await flush()
  assert.equal(counts.value, null)
  user.value = { id: 11, isAdmin: true, emailVerified: true }
  await vue.nextTick()
  requests[1].resolve({ userId: 11, data: { approvals: 0, reports: 0, tickets: 0 } })
  await flush()
  assert.equal(counts.value.approvals, 0)
})

test('a completed admin action refreshes counts once across desktop and mobile sidebars', async () => {
  const { useCounts, requests, window } = await countsClient()
  const user = vue.ref({ id: 9, isAdmin: true, emailVerified: true })
  const counts = useCounts(() => user.value)
  useCounts(() => user.value)
  requests[0].resolve({ userId: 9, data: { approvals: 2, reports: 3, tickets: 4 } })
  await flush()
  window.dispatchEvent(new Event('wave:admin-counts-changed'))
  assert.equal(requests.length, 2)
  requests[1].resolve({ userId: 9, data: { approvals: 1, reports: 2, tickets: 3 } })
  await flush()
  assert.equal(requests.length, 2)
  assert.equal(counts.value.reports, 2)
})
