import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

test('review submission waits for eligibility, blocks duplicate sends, and retains failed drafts', async () => {
  const calls = []
  let finish
  const { state } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': {
      apiGet: async () => ({ data: { canReview: true, own: null } }),
      apiRequest: (path, options) => { calls.push([path, options]); return new Promise(resolve => { finish = resolve }) },
      formatDate: () => '',
    },
  }, { applicationId: 17 })
  await state.submit()
  assert.equal(calls.length, 0)
  await state.show()
  assert.equal(state.open.value, true)
  await state.submit()
  assert.equal(calls.length, 0)
  assert.equal(state.error.value, 'reviews.chooseRating')
  state.rating.value = 5
  state.comment.value = 'A wonderful collaboration.'
  const pending = state.submit()
  await state.submit()
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/me/applications/17/review')
  assert.equal(calls[0][1].body.rating, 5)
  finish({ data: { canReview: false, own: { rating: 5, comment: 'A wonderful collaboration.' } } })
  await pending
  assert.equal(state.state.value.canReview, false)
  assert.equal(state.comment.value, '')
  await state.submit()
  assert.equal(calls.length, 1)
})

test('a failed review keeps its comment and rating for retry', async () => {
  const { state } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': {
      apiGet: async () => ({ data: { canReview: true } }),
      apiRequest: async () => { throw new Error('Server error') }, formatDate: () => '',
    },
  }, { applicationId: 18 })
  await state.show()
  state.rating.value = 4
  state.comment.value = 'Keep this draft after an error.'
  await state.submit()
  assert.equal(state.error.value, 'Server error')
  assert.equal(state.open.value, true)
  assert.equal(state.comment.value, 'Keep this draft after an error.')
})

test('private totals ignore responses for an earlier account', async () => {
  const requests = []
  const props = reactive({ user: { id: 1, approved: true }, profile: {}, countryOptions: [] })
  const { state } = await setupView('../src/components/account/AccountProfileSummary.vue', {
    '../../lib/api': { apiGet: () => new Promise(resolve => { requests.push(resolve) }), formatMoney: () => '' },
  }, props)
  props.user = { id: 2, approved: true }
  await nextTick()
  requests[1]({ userId: 2, data: { totals: [{ currency: 'BAM', amount: 222 }] } })
  await nextTick()
  requests[0]({ userId: 1, data: { totals: [{ currency: 'BAM', amount: 111 }] } })
  await nextTick()
  assert.equal(state.financial.value.totals[0].amount, 222)
})

test('password mismatches do not send; failures retain the form and success erases all password fields', async () => {
  const requests = []
  let fail = true
  const { state } = await setupView('../src/components/account/PasswordChangeAction.vue', {
    '../../lib/api': { apiRequest: async (path, options) => { requests.push([path, options]); if (fail) throw new Error('Current password is incorrect') } },
  }, { email: 'creator@example.test' })
  state.show()
  state.fields.currentPassword = 'old-password-value'
  state.fields.newPassword = 'new-password-value'
  state.fields.confirmPassword = 'different-password-value'
  await state.save()
  assert.equal(requests.length, 0)
  state.fields.confirmPassword = state.fields.newPassword
  await state.save()
  assert.equal(state.error.value, 'Current password is incorrect')
  assert.equal(state.open.value, true)
  assert.equal(state.fields.newPassword, 'new-password-value')
  fail = false
  await state.save()
  assert.equal(requests[1][0], '/me/password')
  assert.equal(requests[1][1].body.currentPassword, 'old-password-value')
  assert.equal(state.open.value, false)
  assert.equal(state.success.value, true)
  assert.equal(state.fields.currentPassword, '')
  assert.equal(state.fields.newPassword, '')
  assert.equal(state.fields.confirmPassword, '')
})
