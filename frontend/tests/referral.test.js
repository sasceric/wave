import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, ref } from 'vue'
import { setupView } from './setupView.js'
import { forgetReferral, normalizeReferralCode, rememberReferral, storedReferral } from '../src/lib/referral.js'

function memoryStorage() {
  const values = new Map()
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)), removeItem: key => values.delete(key) }
}

test('referral codes from ?ref= are normalised and remembered for 30 days', () => {
  const storage = memoryStorage()
  assert.equal(normalizeReferralCode(' amar '), 'AMAR')
  assert.equal(normalizeReferralCode('a-b'), '')
  assert.equal(rememberReferral('<script>', storage, 0), '')
  assert.equal(storedReferral(storage, 0), '')
  assert.equal(rememberReferral('amar27', storage, 1000), 'AMAR27')
  assert.equal(storedReferral(storage, 1000 + 29 * 86400000), 'AMAR27')
  assert.equal(storedReferral(storage, 1000 + 31 * 86400000), '')
  forgetReferral(storage)
  assert.equal(storedReferral(storage, 1000), '')
})

async function flushReferralUpdates() {
  await Promise.resolve()
  await nextTick()
  await Promise.resolve()
}

function deferred() {
  let resolve
  const promise = new Promise(done => { resolve = done })
  return { promise, resolve }
}

test('creator referrals clear private account data and ignore a previous account QR result', async () => {
  const user = ref({ id: 1, accountType: 'creator' })
  const requests = []
  const images = []
  const view = await setupView('../src/views/AccountReferralsView.vue', {
    '../composables/useCurrentUser': { currentUser: user },
    '../lib/api': { apiGet: () => { const task = deferred(); requests.push(task); return task.promise }, formatDate: String },
    '../lib/qrImage': { qrImages: () => { const task = deferred(); images.push(task); return task.promise } },
    '../lib/toasts': { actionToasts: { notify() {} } },
  }, {}, { clearTimeout, setTimeout })
  requests[0].resolve({ data: { code: 'AMAR', link: 'https://wave.ba/?ref=AMAR' } })
  await flushReferralUpdates()
  assert.equal(view.state.data.value.code, 'AMAR')
  user.value = { id: 2, accountType: 'creator' }
  await flushReferralUpdates()
  assert.equal(view.state.data.value, null)
  requests[1].resolve({ data: { code: 'LEJLA', link: 'https://wave.ba/?ref=LEJLA' } })
  await flushReferralUpdates()
  images[1].resolve({ png: 'lejla-qr' })
  await flushReferralUpdates()
  images[0].resolve({ png: 'amar-qr' })
  await flushReferralUpdates()
  assert.equal(view.state.data.value.code, 'LEJLA')
  assert.equal(view.state.qr.value, 'lejla-qr')
  user.value = null
  await flushReferralUpdates()
  assert.equal(view.state.data.value, null)
  assert.equal(view.state.qr.value, '')
})

test('admin referral data reloads when switching between administrators and clears on logout', async () => {
  const user = ref({ id: 1, isAdmin: true })
  const requests = []
  const view = await setupView('../src/views/AdminReferralsView.vue', {
    '../composables/useCurrentUser': { currentUser: user },
    '../lib/api': { apiGet: () => { const task = deferred(); requests.push(task); return task.promise }, apiRequest: async () => ({}), formatDate: String },
  })
  requests[0].resolve({ data: { ambassadors: [{ name: 'First response' }], review: [] } })
  await flushReferralUpdates()
  assert.equal(view.state.data.value.ambassadors[0].name, 'First response')
  user.value = { id: 2, isAdmin: true }
  await flushReferralUpdates()
  assert.equal(view.state.data.value, null)
  assert.equal(requests.length, 2)
  user.value = null
  await flushReferralUpdates()
  requests[1].resolve({ data: { ambassadors: [{ name: 'Stale response' }], review: [] } })
  await flushReferralUpdates()
  assert.equal(view.state.data.value, null)
  assert.equal(view.state.loading.value, false)
})
