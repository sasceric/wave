import assert from 'node:assert/strict'
import test from 'node:test'
import { reactive, ref, nextTick } from 'vue'
import { setupView } from './setupView.js'

function accountModules(apiGet) {
  return {
    '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
    '../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({ bookmarks: ref([]), loadCampaignBookmarks: async () => {} }) },
    '../lib/phoneNumbers': { formatInternationalPhoneNumber: (value) => value },
    '../lib/api': { apiGet, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  }
}

test('account profile renders after the session without loading unrelated marketplace sections', async () => {
  for (const accountType of ['company', 'creator']) {
    const paths = []
    const { state } = await setupView('../src/views/AccountView.vue', accountModules(async path => {
      paths.push(path)
      return { data: { id: 1, accountType, approved: true, profile: { name: 'Company', socialProfiles: [], tags: [] } } }
    }), {}, { structuredClone })
    await state.loadDashboard()
    assert.deepEqual(paths, ['/auth/session'])
    assert.equal(state.dashboardReady.value, true)
    assert.equal(state.dashboardLoading.value, false)
  }
})

test('company campaign list avoids applicant fan-out and only the opened campaign loads its creators', async () => {
  const route = reactive({ query: {}, params: {}, meta: { accountSection: 'campaigns', accountCampaignPage: 'list' } })
  const paths = []
  const account = { id: 1, accountType: 'company', approved: true, profile: { name: 'Company', socialProfiles: [] } }
  const { state } = await setupView('../src/views/AccountView.vue', {
    ...accountModules(async path => {
      paths.push(path)
      if (path === '/auth/session') return { data: account }
      if (path === '/me/campaigns') return { data: [{ slug: 'one' }, { slug: 'two' }, { slug: 'three' }] }
      return { data: [] }
    }),
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
  }, {}, { structuredClone })
  await state.loadDashboard()
  assert.deepEqual(paths, ['/auth/session', '/me/campaigns'])
  route.params.slug = 'two'
  route.meta.accountCampaignPage = 'detail'
  await new Promise(resolve => setImmediate(resolve))
  assert.deepEqual(paths.slice(2), ['/me/campaigns', '/company/campaigns/two/applications'])
  assert.equal(state.dashboardReady.value, true)
})

test('an anonymous session ends the skeleton state and shows sign-in; errors never leave it spinning', async () => {
  for (const status of [401, 503]) {
    const { state } = await setupView('../src/views/AccountView.vue', accountModules(async () => {
      throw Object.assign(new Error('Request failed'), { status })
    }))
    await state.loadDashboard()
    assert.equal(state.dashboardReady.value, true)
    assert.equal(state.dashboardLoading.value, false)
    assert.equal(state.user.value, null)
    if (status === 503) assert.equal(state.error.value, 'Request failed')
  }
})

test('history placeholders disappear before restoring the anchor, including a failed request', async () => {
  let rejectHistory
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: () => new Promise((resolve, reject) => { rejectHistory = reject }), apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  state.hasOlderMessages.value = true
  state.conversationMessages.value = [{ id: 51, senderId: 2, body: 'Keep my position' }]
  let checks = 0
  const anchor = { dataset: { messageId: '51' }, getBoundingClientRect: () => {
    checks += 1
    assert.equal(state.loadingOlderMessages.value, false)
    return { top: checks === 1 ? 100 : 125 }
  } }
  state.messageList.value = { scrollTop: 20, querySelector: () => anchor }
  const request = state.loadOlderMessages()
  assert.equal(state.loadingOlderMessages.value, true)
  rejectHistory(new Error('History unavailable'))
  await request
  assert.equal(state.historyError.value, 'History unavailable')
  assert.equal(state.messageList.value.scrollTop, 45)
  assert.equal(state.conversationMessages.value[0].id, 51)
})


test('a notification selecting another chat replaces the old interactive thread with loading placeholders', async () => {
  const route = reactive({ query: { conversation: '1' }, params: {}, meta: {} })
  let requests = 0
  const { state } = await setupView('../src/views/MessagesView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
    '../lib/api': { apiGet: () => { requests += 1; return new Promise(() => {}) }, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
  })
  state.conversations.value = [{ id: 1 }]
  state.selectedConversationId.value = 1
  state.inboxLoaded.value = true
  route.query.conversation = '2'
  await nextTick()
  assert.equal(state.inboxLoaded.value, false)
  assert.equal(state.loading.value, true)
  assert.equal(requests, 1)
})


test('login and registration use a successful guest session instead of the protected account endpoint', async () => {
  for (const mode of ['login', 'register']) {
    const paths = []
    const { state } = await setupView('../src/views/AccountView.vue', {
      ...accountModules(async path => { paths.push(path); return { data: null } }),
      'vue-router': { useRoute: () => ({ query: { mode }, params: {}, meta: {} }), useRouter: () => ({ replace: async () => {} }) },
    })
    await state.loadDashboard()
    assert.deepEqual(paths, ['/auth/session'])
    assert.equal(state.user.value, null)
    assert.equal(state.error.value, '')
    assert.equal(state.dashboardReady.value, true)
    assert.equal(state.dashboardLoading.value, false)
  }
})

test('switching account sections invalidates slow unrelated activity without replacing the profile draft', async () => {
  const route = reactive({ query: {}, params: {}, meta: { accountSection: 'applications' } })
  const pending = new Map()
  const { state } = await setupView('../src/views/AccountView.vue', {
    ...accountModules(path => path === '/auth/session'
      ? Promise.resolve({ data: { id: 1, accountType: 'creator', approved: true, profile: { displayName: 'Creator', tags: [] } } })
      : new Promise(resolve => pending.set(path, resolve))),
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async () => {} }) },
  }, {}, { structuredClone })
  const first = state.loadDashboard()
  await new Promise(resolve => setImmediate(resolve))
  state.profile.value.displayName = 'Unsaved profile draft'
  route.meta.accountSection = 'inquiries'
  await nextTick()
  pending.get('/me/inquiries')({ data: [{ id: 22 }] })
  await new Promise(resolve => setImmediate(resolve))
  for (const path of ['/me/applications', '/me/offers', '/me/invitations']) pending.get(path)({ data: [{ id: 11 }] })
  await first
  assert.equal(state.applications.value.length, 0)
  assert.equal(state.inquiries.value[0].id, 22)
  assert.equal(state.profile.value.displayName, 'Unsaved profile draft')
  assert.equal(state.dashboardLoading.value, false)
})
