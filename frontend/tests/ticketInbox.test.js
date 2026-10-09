import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { ref } from 'vue'
import { setupView } from './setupView.js'
import { ticketCategories, ticketSubmissionKey, validTicketFiles } from '../src/lib/supportTicket.js'
import { notificationDestination } from '../src/lib/notificationNavigation.js'

async function inbox(request, route = { meta: { routeName: 'account-support' }, query: {} }) {
  return setupView('../src/views/TicketInboxView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async () => {} }) },
    '../composables/useCurrentUser': { currentUser: ref({ id: 7 }) },
    '../lib/supportTicket': { ticketCategories, ticketSubmissionKey, validTicketFiles },
    '../lib/api': { apiRequest: request, formatDate: value => value },
  }, {}, { FormData })
}

test('ticket inbox lazily loads cursor batches and never selects the first report automatically', async () => {
  const calls = []
  const { state } = await inbox(async path => {
    calls.push(path)
    return { data: path.includes('cursor=') ? [{ id: 1 }, { id: 2 }] : [{ id: 1 }], meta: { counts: { all: 2 }, hasMore: !path.includes('cursor='), nextCursor: 'next' } }
  })
  await state.initialize()
  assert.equal(state.selected.value, null)
  assert.equal(calls.length, 1)
  await state.loadList()
  assert.ok(calls[1].includes('cursor=next'))
  assert.equal(state.rows.value.length, 2)
  assert.equal(state.hasMore.value, false)
})

test('stale ticket and list responses cannot reveal data from a previous selection', async () => {
  let resolveOld
  const route = { meta: { routeName: 'account-support' }, query: { ticket: '1' } }
  const { state } = await inbox(async path => {
    if (path.includes('/1')) return new Promise(resolve => { if (!path.includes('messages')) resolveOld = resolve; else resolve({ data: [], meta: { hasMore: false } }) })
    if (path.includes('messages')) return { data: [], meta: { hasMore: false } }
    return { data: { id: 2, title: 'My second ticket' } }
  }, route)
  const first = state.loadDetail()
  route.query.ticket = '2'
  await state.loadDetail()
  resolveOld({ data: { id: 1, title: 'Private old report' } })
  await first
  assert.equal(state.selected.value.id, 2)
  assert.equal(state.detailBusy.value, false)
})

test('failed access clears private ticket rows and conversations', async () => {
  const { state } = await inbox(async () => { throw Object.assign(new Error('Forbidden'), { status: 403 }) })
  state.rows.value = [{ id: 77, title: 'Private' }]
  state.selected.value = { id: 77 }
  state.messages.value = [{ body: 'Private' }]
  await state.loadList(true)
  assert.equal(state.access.value, 'forbidden')
  assert.equal(state.rows.value.length, 0)
  assert.equal(state.messages.value.length, 0)
  assert.equal(state.selected.value, null)
})

test('replies preserve idempotency keys on failure, guard duplicate sends, and use multipart compression upload path', async () => {
  const keys = []
  let resolveSend
  let fail = true
  const { state } = await inbox(async (path, options) => {
    if (options?.method === 'POST') {
      keys.push(options.body.get('submissionKey'))
      assert.equal(options.body.get('body'), 'Please help with this bug.')
      assert.equal(options.body.getAll('attachments[]').length, 1)
      if (fail) throw new Error('Offline')
      return new Promise(resolve => { resolveSend = resolve })
    }
    return { data: [], meta: { hasMore: false, counts: {} } }
  })
  state.selected.value = { id: 10, status: 'open' }
  state.body.value = 'Please help with this bug.'
  state.files.value = [new File(['%PDF-1.4\nTest'], 'proof.pdf', { type: 'application/pdf' })]
  await state.sendReply()
  assert.equal(state.replyError.value, 'Offline')
  fail = false
  const pending = state.sendReply()
  await state.sendReply()
  assert.equal(keys.length, 2)
  assert.equal(keys[0], keys[1])
  resolveSend({ data: { id: 1, body: 'Reply', internal: false }, status: 'open' })
  await pending
  assert.equal(state.messages.value.length, 1)
  assert.equal(state.body.value, '')
  assert.equal(state.files.value.length, 0)
})

test('older replies are prepended without duplicating overlaps, and ticket history has no live subscription', async () => {
  const { state } = await inbox(async () => ({ data: [{ id: 1 }, { id: 2 }], meta: { hasMore: false } }))
  state.selected.value = { id: 10 }
  state.messages.value = [{ id: 2 }, { id: 3 }]
  state.historyMore.value = true
  state.historyCursor.value = 3
  await state.loadOlder()
  assert.deepEqual(Array.from(state.messages.value, message => message.id), [1, 2, 3])
  const source = await readFile(new URL('../src/views/TicketInboxView.vue', import.meta.url), 'utf8')
  assert.doesNotMatch(source, /EventSource|WebSocket|setInterval|subscribeRealtime/)
  assert.match(source, /DirectoryLoadMore/)
})

test('ticket notification deep links open the matching customer or admin report', () => {
  assert.deepEqual(notificationDestination({ supportTicketId: 12, supportAdmin: true }), { name: 'admin-support', query: { ticket: 12 } })
  assert.deepEqual(notificationDestination({ supportTicketId: 12, supportAdmin: false }), { name: 'account-support', query: { ticket: 12 } })
})

test('support deep links survive login even while profile completion is pending', async () => {
  const replaced = []
  const route = { params: {}, query: { mode: 'login', returnTo: '/racun/tiketi?ticket=12' }, meta: { accountSection: 'profile' } }
  const view = await setupView('../src/views/AccountView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async target => { replaced.push(target) } }) },
    '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
    '../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({ bookmarks: ref([]), loadCampaignBookmarks: async () => {} }) },
    '../routePaths': { localizedPath: name => name === 'account-support' ? '/racun/tiketi' : '/admin/tiketi', localizedRouteName: (name, locale) => `${name}-${locale}` },
    '../lib/api': { apiGet: async () => ({ data: [] }), apiRequest: async () => {}, formatMoney: () => '' },
  }, {}, { structuredClone })
  view.windowTarget.location = { origin: 'http://127.0.0.1:8012' }
  await view.state.loadDashboard({ id: 7, isAdmin: false, approved: false, emailVerified: false, profile: null })
  assert.deepEqual(replaced, ['/racun/tiketi?ticket=12'])
  assert.equal(view.state.error.value, '')
})
