import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'
import { notificationDestination } from '../src/lib/notificationNavigation.js'

test('assignment waits for an account selection and posts its user ID without crediting a wallet', async () => {
  const requests = []
  let reject = true
  const { state } = await setupView('../src/views/AdminCreditSettingsView.vue', {
    '../lib/api': { formatDate: () => '', apiGet: async () => ({ data: { rows: [], page: 1, total: 0 } }), apiRequest: async (path, options) => { requests.push([path, options]); if (reject) throw new Error('Email unavailable') } },
  })
  state.openAssignment({ id: 'voucher-id', code: 'WAVE1234', amount: 100 })
  await state.assign()
  assert.equal(requests.length, 0)
  state.recipientType.value = 'creator'
  await nextTick()
  state.recipientId.value = 543
  await state.assign()
  assert.equal(requests[0][0], '/admin/credits/vouchers/voucher-id/assign')
  assert.deepEqual(JSON.parse(JSON.stringify(requests[0][1])), { method: 'POST', body: { accountType: 'creator', userId: 543 } })
  assert.equal(state.assignmentError.value, 'Email unavailable')
  assert.equal(state.assigning.value.id, 'voucher-id')
  reject = false
  await state.assign()
  assert.equal(state.assigning.value, null)
  assert.equal(state.notice.value, 'credits.assignmentSuccess')
  assert.equal(requests.length, 2)
})

test('recipient dropdown fetches twenty accounts per scroll and emits actual user IDs', async () => {
  const paths = []
  const row = id => ({ id, name: `Name ${id}`, email: `${id}@example.test` })
  const { state, emitted } = await setupView('../src/components/admin/CreditRecipientSelect.vue', {
    '../../lib/api': { apiGet: async path => { paths.push(path); return { data: paths.length === 1
      ? { rows: Array.from({ length: 20 }, (_, n) => row(n + 100)), nextCursor: 'next-page', hasMore: true }
      : { rows: [row(120), row(121)], nextCursor: null, hasMore: false } } } },
  }, { accountType: 'creator', modelValue: null }, { setTimeout, clearTimeout })
  await state.open()
  assert.equal(state.rows.value.length, 20)
  assert.match(paths[0], /accountType=creator&q=&limit=20/)
  state.scrolled({ target: { scrollTop: 150, clientHeight: 100, scrollHeight: 250 } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.rows.value.length, 22)
  assert.match(paths[1], /cursor=next-page/)
  state.choose(state.rows.value[21])
  assert.deepEqual(Array.from(emitted[0]), ['update:modelValue', 121])
  assert.equal(state.selected.value.email, '121@example.test')
  assert.equal(state.isOpen.value, false)
})

test('recipient type changes discard responses for the old account type', async () => {
  let complete
  const props = reactive({ accountType: 'creator', modelValue: null })
  const { state, emitted } = await setupView('../src/components/admin/CreditRecipientSelect.vue', {
    '../../lib/api': { apiGet: () => new Promise(resolve => { complete = resolve }) },
  }, props, { setTimeout, clearTimeout })
  const pending = state.load(true)
  props.accountType = 'company'
  await nextTick()
  complete({ data: { rows: [{ id: 55, name: 'Old creator', email: 'old@example.test' }], hasMore: false } })
  await pending
  assert.equal(state.rows.value.length, 0)
  assert.equal(state.loading.value, false)
  assert.deepEqual(Array.from(emitted[0]), ['update:modelValue', null])
})

test('review notifications open the correct campaign review form for each recipient', () => {
  const common = { reviewApplicationId: 92, campaign: { slug: 'summer-story' } }
  assert.deepEqual(notificationDestination({ ...common, reviewAccountType: 'company' }), { name: 'account-campaign-detail', params: { slug: 'summer-story' }, query: { review: 92 } })
  assert.deepEqual(notificationDestination({ ...common, reviewAccountType: 'creator' }), { name: 'account-applications', query: { review: 92 } })
})

test('review links automatically open the ownership-checked review dialog', async () => {
  const paths = []
  const { state } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': { apiGet: async path => { paths.push(path); return { data: { canReview: true } } }, apiRequest: async () => {}, formatDate: () => '' },
  }, { applicationId: 92, autoOpen: true, hideTrigger: true })
  await nextTick()
  assert.deepEqual(paths, ['/me/applications/92/review'])
  assert.equal(state.open.value, true)
})
