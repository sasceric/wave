import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref, nextTick } from 'vue'
import { accountAccessRedirect, accountLanding } from '../src/lib/accountAccess.js'
import { campaignActivityStage } from '../src/lib/campaignActivity.js'
import { setupView } from './setupView.js'

test('login lands on overview, incomplete accounts can use the setup overview, collaboration waits for approval and verification', () => {
  const ready = { approved: true, emailVerified: true, profileComplete: true }
  const route = name => ({ meta: { routeName: name } })
  assert.equal(accountLanding(ready), 'account-dashboard')
  assert.equal(accountLanding({ ...ready, profileComplete: false }), 'account-dashboard')
  for (const name of ['home', 'campaigns', 'messages', 'account-applications']) assert.deepEqual(accountAccessRedirect(route(name), { ...ready, profileComplete: false }), { name: 'account' })
  for (const name of ['account-dashboard', 'account', 'support-create', 'account-support', 'verify-email', 'privacy-policy']) assert.equal(accountAccessRedirect(route(name), { ...ready, profileComplete: false }), null)
  for (const flag of ['approved', 'emailVerified']) {
    for (const name of ['messages', 'account-campaigns', 'account-applications', 'account-reviews']) assert.deepEqual(accountAccessRedirect(route(name), { ...ready, [flag]: false }), { name: 'account-dashboard' })
    for (const name of ['account-dashboard', 'account', 'account-support']) assert.equal(accountAccessRedirect(route(name), { ...ready, [flag]: false }), null)
  }
  assert.equal(accountAccessRedirect(route('messages'), { ...ready, hide_my_account: 1 }), null)
  assert.equal(accountAccessRedirect(route('admin'), { isAdmin: true, profileComplete: false }), null)
  assert.equal(accountAccessRedirect(route('home'), null), null)
})

test('overview discards private stale results on account switches and handles mismatched session identity', async () => {
  const user = ref({ id: 1, accountType: 'creator', approved: true, emailVerified: true, profileComplete: true })
  const pending = []
  let reloaded = 0
  const { state } = await setupView('../src/views/AccountDashboardView.vue', {
    '../composables/useCurrentUser': { currentUser: user, loadCurrentUser: async () => { reloaded++ } },
    '../lib/campaignActivity': { campaignActivityStage },
    '../lib/api': { apiGet: path => new Promise(resolve => pending.push({ path, resolve })), formatDate: String, formatMoney: String },
  })
  assert.equal(pending[0].path, '/me/dashboard')
  user.value = { ...user.value, id: 2, accountType: 'company' }
  await nextTick()
  pending[0].resolve({ userId: 1, data: { ready: true, counts: { applications: 8 } } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.snapshot.value, null)
  pending[1].resolve({ userId: 2, data: { ready: true, counts: { campaigns: 3 }, recent: [] } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.metrics.value[0].count, 3)
  assert.equal(state.creator.value, false)
  assert.deepEqual(JSON.parse(JSON.stringify(state.activityLink({ slug: 'demo' }))), { name: 'account-campaign-detail', params: { slug: 'demo' } })
  const request = state.load()
  pending[2].resolve({ userId: 1, data: { ready: true } })
  await request
  assert.equal(reloaded, 1)
  assert.equal(state.snapshot.value, null)
})
