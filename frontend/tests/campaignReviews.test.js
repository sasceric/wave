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

test('admin editing prefills only their own review and saves through PATCH', async () => {
  const own = { rating: 3, comment: 'Original administrator review.' }
  const received = { rating: 1, comment: 'Other participant feedback.' }
  const requests = []
  const { state, emitted } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': {
      apiGet: async () => ({ data: { canReview: false, canEditOwn: true, own, received } }),
      apiRequest: async (path, options) => {
        requests.push([path, options])
        return { data: { canReview: false, canEditOwn: true, own: options.body, received } }
      },
      formatDate: () => '',
    },
  }, { applicationId: 19, editOnOpen: true })
  await state.show()
  assert.equal(state.editing.value, true)
  assert.equal(state.rating.value, 3)
  assert.equal(state.comment.value, own.comment)
  state.rating.value = 5
  state.comment.value = 'Corrected administrator review.'
  await state.submit()
  assert.equal(requests.length, 1)
  assert.equal(requests[0][0], '/me/applications/19/review')
  assert.equal(requests[0][1].method, 'PATCH')
  assert.equal(state.state.value.own.rating, 5)
  assert.equal(state.state.value.received.comment, received.comment)
  assert.equal(state.editing.value, false)
  assert.equal(emitted[0][0], 'saved')
})

test('an edit failure preserves the draft and prevents duplicate saves', async () => {
  let fail
  const requests = []
  const { state } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': {
      apiGet: async () => ({ data: { canEditOwn: true, own: { rating: 4, comment: 'Original feedback text.' } } }),
      apiRequest: (path, options) => { requests.push([path, options]); return new Promise((resolve, reject) => { fail = reject }) },
      formatDate: () => '',
    },
  }, { applicationId: 20 })
  await state.show()
  state.edit()
  state.comment.value = 'Keep my corrected review for retry.'
  const pending = state.submit()
  await state.submit()
  assert.equal(requests.length, 1)
  fail(new Error('Could not save'))
  await pending
  assert.equal(state.error.value, 'Could not save')
  assert.equal(state.editing.value, true)
  assert.equal(state.open.value, true)
  assert.equal(state.comment.value, 'Keep my corrected review for retry.')
  assert.equal(state.state.value.own.comment, 'Original feedback text.')
})

test('existing reviews without admin edit permission cannot enter edit mode or send', async () => {
  let requests = 0
  const { state } = await setupView('../src/components/reviews/ReviewAction.vue', {
    '../../lib/api': {
      apiGet: async () => ({ data: { canReview: false, canEditOwn: false, own: { rating: 4, comment: 'Submitted ordinary user review.' } } }),
      apiRequest: async () => { requests++ }, formatDate: () => '',
    },
  }, { applicationId: 21, editOnOpen: true })
  await state.show()
  state.edit()
  await state.submit()
  assert.equal(state.editing.value, false)
  assert.equal(requests, 0)
})

test('My reviews only opens the editor for an admin-authored review', async () => {
  const { state, currentUser } = await setupView('../src/views/AdminSafetyView.vue', {
    '../lib/api': { apiRequest: async () => ({}), apiGet: async () => ({ data: [], meta: { total: 0, page: 1 } }), formatDate: () => '' },
    'vue-router': { useRoute: () => ({ meta: { routeName: 'account-reviews' } }) },
  })
  const row = { id: 37, given: { rating: 4, comment: 'Own review' } }
  state.editReview(row)
  assert.equal(state.editingReview.value, null)
  currentUser.value = { id: 1, isAdmin: true }
  await nextTick()
  state.editReview({ id: 38, received: { rating: 5 } })
  assert.equal(state.editingReview.value, null)
  state.view(row)
  state.editReview(row)
  assert.equal(state.editingReview.value, 37)
  assert.equal(state.selected.value, null)
})
