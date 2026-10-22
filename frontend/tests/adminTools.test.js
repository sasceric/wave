import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { nextTick, ref } from 'vue'
import { setupView } from './setupView.js'

const view = '../src/views/AdminToolsView.vue'
async function tools(apiGet) {
  const currentUser = ref({ id: 1, isAdmin: true })
  const result = await setupView(view, {
    '../lib/api': { apiGet, apiRequest: apiGet, formatDate: () => '22.10.2026' },
    '../composables/useCurrentUser': { currentUser },
  }, {}, { URLSearchParams })
  return { ...result, currentUser }
}
async function settle() {
  await nextTick()
  await new Promise((resolve) => setImmediate(resolve))
}

test('only overdue scheduled tasks use the overdue status badge', async () => {
  const { state } = await tools(async () => ({ data: [], meta: {} }))
  assert.equal(state.taskStatus({ status: 'scheduled', overdue: true }), 'overdue')
  assert.equal(state.taskStatus({ status: 'scheduled', overdue: false }), 'scheduled')
  for (const status of ['queued', 'running', 'failed', 'inactive', 'unregistered']) {
    assert.equal(state.taskStatus({ status, overdue: true }), status)
  }
})

test('log pagination jumps directly to the last page and resets snapshots on filter changes', async () => {
  const requests = []
  const { state } = await tools(async (path) => {
    requests.push(path)
    if (path.includes('log-files')) return { data: { files: [{ name: 'prod.log' }] } }
    if (path.includes('/logs?')) {
      const query = new URLSearchParams(path.split('?')[1])
      const cursor = query.get('cursor')
      const page = Number(query.get('page'))
      return { data: [{ id: cursor || 'first' }], meta: { page, total: 875, cursor: cursor || 'snapshot-first', hasMore: page < 35 } }
    }
    return { data: [], meta: { total: 0, hasMore: false } }
  })
  await state.load()
  state.tab.value = 'logs'
  await settle()
  assert.equal(state.rows.value.length, 0)
  state.file.value = '__all__'
  await settle()
  assert.equal(state.rows.value[0].id, 'first')
  state.navigatePage(35)
  await settle()
  assert.equal(state.page.value, 35)
  assert.ok(requests.at(-1).includes('page=35'))
  assert.equal(state.rows.value[0].id, 'snapshot-first')
  state.navigatePage(1)
  await settle()
  assert.equal(state.page.value, 1)
  assert.ok(requests.at(-1).includes('cursor=snapshot-first'))
  state.pageSize.value = 50
  await settle()
  assert.ok(requests.at(-1).includes('pageSize=50'))
  assert.ok(requests.at(-1).endsWith('cursor='))
  state.file.value = 'prod.log'
  await settle()
  assert.ok(requests.at(-1).includes('file=prod.log'))
  assert.equal(state.page.value, 1)
})

test('late responses cannot replace a newly selected tab and forbidden access clears rows', async () => {
  let resolveFirst
  let forbidden = false
  const { state } = await tools(async (path) => {
    if (forbidden) throw Object.assign(new Error('forbidden'), { status: 403 })
    if (path.includes('/tasks?')) return new Promise((resolve) => { resolveFirst = resolve })
    return { data: [{ id: 'queue-row' }], meta: { total: 1, hasMore: false } }
  })
  const first = state.load()
  state.tab.value = 'queues'
  await settle()
  resolveFirst({ data: [{ id: 'outdated-task' }], meta: { total: 1 } })
  await first
  assert.equal(state.rows.value[0].id, 'queue-row')
  forbidden = true
  await state.load()
  assert.equal(state.access.value, 'forbidden')
  assert.equal(state.rows.value.length, 0)
})

test('all six locales include the tools route and complete interface copy', async () => {
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url)))
  let expected
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    const keys = Object.keys(catalog.adminTools).sort()
    expected ??= keys
    assert.deepEqual(keys, expected)
    assert.ok(routes[locale]['admin-tools'])
    assert.equal(Object.keys(catalog.adminTools.statuses).length, 13)
  }
})

test('failed jobs load through their own endpoint and retry refreshes the failed list', async () => {
  const requests = []
  let failed = true
  const { state } = await tools(async (path, options) => {
    requests.push(path)
    if (path === '/admin/tools/failed/17/retry') {
      assert.equal(options.method, 'POST')
      failed = false
      return { data: { accepted: true } }
    }
    if (path.startsWith('/admin/tools/failed?')) {
      return { data: failed ? [{ id: 17, type: 'SendWebPushMessage', attempts: 6, error_code: 'TypeError' }] : [], meta: { page: 1, pageSize: 25, hasMore: false } }
    }
    return { data: [], meta: { hasMore: false } }
  })
  await state.load()
  state.tab.value = 'queues'
  state.showFailed.value = true
  await settle()
  assert.ok(requests.includes('/admin/tools/failed?page=1&pageSize=25'))
  assert.equal(state.rows.value[0].id, 17)
  assert.equal(state.rows.value[0].attempts, 6)
  await state.action('failed/17/retry')
  await settle()
  assert.equal(state.rows.value.length, 0)
  assert.equal(state.error.value, '')
  assert.equal(state.actionBusy.value, false)
})

test('selecting a mail file exposes recipients, subject, status and protocol columns', async () => {
  const { state } = await tools(async path => path.includes('log-files')
    ? { data: { files: [{ name: 'prod.mail-2026-10-22.log' }] } }
    : { data: [], meta: {} })
  state.tab.value = 'logs'
  state.file.value = 'prod.mail-2026-10-22.log'
  await settle()
  assert.deepEqual(Array.from(state.columns.value, column => column.key), ['time', 'recipient', 'subject', 'mailStatus', 'responseCode', 'message'])
  state.file.value = '__all__'
  await settle()
  assert.deepEqual(Array.from(state.columns.value, column => column.key), ['time', 'channel', 'level', 'message'])
})

test('mail preview loads only on demand and late private content is discarded after closing or changing accounts', async () => {
  let finish
  const calls = []
  const { state, currentUser } = await tools(async path => {
    calls.push(path)
    if (path.includes('email-preview')) return new Promise(resolve => { finish = resolve })
    return { data: [], meta: {} }
  })
  const row = { id: 'file:120', mail: { emailId: 'a'.repeat(32), status: 'accepted', subject: 'Confirmation' } }
  const pending = state.openLog(row)
  assert.equal(state.previewLoading.value, true)
  assert.equal(calls[0], `/admin/tools/email-preview/${'a'.repeat(32)}`)
  state.closeLog()
  finish({ data: { html: '<p>Old private content</p>', text: 'Old private content' } })
  await pending
  assert.equal(state.emailPreview.value, null)
  const another = state.openLog(row)
  currentUser.value = { id: 2, isAdmin: false }
  await settle()
  finish({ data: { html: '<p>Previous admin content</p>' } })
  await another
  assert.equal(state.selectedLog.value, null)
  assert.equal(state.emailPreview.value, null)
})

test('an expired captured email reports preview unavailable without hiding the send error', async () => {
  const { state } = await tools(async () => { throw Object.assign(new Error('missing'), { status: 404 }) })
  const row = { id: 'mail:0', mail: { emailId: 'b'.repeat(32), error: '550 Recipient rejected' } }
  await state.openLog(row)
  assert.equal(state.previewError.value, 'emailLogs.previewUnavailable')
  assert.equal(state.selectedLog.value.mail.error, '550 Recipient rejected')
  assert.equal(state.previewLoading.value, false)
})

test('email log copy is available in every supported locale and previews cannot execute scripts or load remote content', async () => {
  let expected
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr', 'de']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    const keys = Object.keys(catalog.emailLogs).sort()
    expected ??= keys
    assert.deepEqual(keys, expected)
    assert.equal(Object.keys(catalog.emailLogs.status).length, 5)
  }
  const { state } = await setupView('../src/components/shared/EmailPreviewFrame.vue', {}, { html: '<script>alert(1)</script><img src="https://tracker.example/pixel">', title: 'Email' })
  assert.ok(state.source.value.startsWith('<meta http-equiv="Content-Security-Policy"'))
  assert.ok(state.source.value.includes("default-src 'none'; img-src data:; style-src 'unsafe-inline'"))
  const source = await readFile(new URL('../src/components/shared/EmailPreviewFrame.vue', import.meta.url), 'utf8')
  assert.ok(source.includes('sandbox=""'))
  assert.ok(source.includes('referrerpolicy="no-referrer"'))
})
