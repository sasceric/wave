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

test('account stays in its initial loading layout until session and activity data resolve', async () => {
  const pending = new Map()
  const { state } = await setupView('../src/views/AccountView.vue', accountModules(
    (path) => new Promise((resolve) => pending.set(path, resolve)),
  ), {}, { structuredClone })
  const request = state.loadDashboard()
  assert.equal(state.dashboardLoading.value, true)
  assert.equal(state.dashboardReady.value, false)
  pending.get('/auth/me')({ data: {
    id: 1, accountType: 'company', approved: true, emailVerified: true,
    profile: { name: 'Company', socialProfiles: [] },
  } })
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(state.user.value.id, 1)
  assert.equal(state.dashboardReady.value, false)
  pending.get('/me/campaigns')({ data: [] })
  await new Promise((resolve) => setImmediate(resolve))
  await new Promise((resolve) => setImmediate(resolve))
  pending.get('/me/inquiries')({ data: [] })
  await request
  assert.equal(state.dashboardReady.value, true)
  assert.equal(state.dashboardLoading.value, false)
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
