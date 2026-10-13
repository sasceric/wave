import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, ref } from 'vue'
import { setupView } from './setupView.js'
const flush = () => new Promise(resolve => setImmediate(resolve))
const user = () => ref({ id: 10, approved: true, emailVerified: true, accountType: 'creator', profile: { slug: 'amina' } })

test('profile reviews request ten-row numbered pages and replace rather than append results', async () => {
  const paths = []
  const { state } = await setupView('../src/components/reviews/ProfileReviews.vue', {
    '../../lib/api': { formatDate: () => '', apiGet: async path => { paths.push(path); const page = paths.length; return { data: [{ id: page, rating: 5 }], summary: { reviewCount: 13 }, meta: { total: 13, page } } } },
  }, { kind: 'creators', slug: 'amina' })
  await flush()
  assert.equal(state.rows.value.length, 1)
  assert.equal(state.total.value, 13)
  await state.changePage(2)
  assert.match(paths[1], /reviews\?page=2$/)
  assert.equal(state.page.value, 2)
  assert.equal(state.rows.value.length, 1)
  assert.equal(state.rows.value[0].id, 2)
})

test('reports enforce both reason levels and preserve a failed draft', async () => {
  const currentUser = user()
  const calls = []
  const { state } = await setupView('../src/components/shared/MarketplaceSafetyActions.vue', {
    '../../../../config/report_reasons.json': { default: { spam: ['messages'], other: ['other'] } },
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiUpload: async () => {}, apiRequest: async (path, options) => { calls.push([path, options]); throw new Error('Retry later') } },
  }, { kind: 'company', slug: 'brand' })
  state.report()
  await state.submit()
  assert.equal(calls.length, 0)
  state.category.value = 'spam'
  await nextTick()
  state.reason.value = 'messages'
  state.description.value = 'Repeated unwanted messages.'
  await state.submit()
  assert.equal(calls[0][0], '/me/safety/company/brand/report')
  assert.equal(calls[0][1].body.reason, 'messages')
  assert.equal(state.reporting.value, true)
  assert.equal(state.description.value, 'Repeated unwanted messages.')
  assert.equal(state.error.value, 'Retry later')
  currentUser.value = { ...currentUser.value, id: 20 }
  await nextTick()
  assert.equal(state.reporting.value, false)
  assert.equal(state.description.value, '')
})

test('report image upload retry resumes remaining images without uploading duplicates', async () => {
  const currentUser = user()
  let failed = false
  const uploads = []
  const posted = []
  const { state } = await setupView('../src/components/shared/MarketplaceSafetyActions.vue', {
    '../../../../config/report_reasons.json': { default: { spam: ['messages'] } },
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiUpload: async (path, file) => { uploads.push(file.id); if (file.id === 2 && !failed) { failed = true; throw new Error('Upload failed') } return { data: { id: file.id } } }, apiRequest: async (path, options) => { posted.push([path, options]); return { data: {} } } },
  }, { kind: 'company', slug: 'brand' })
  state.report()
  state.category.value = 'spam'
  await nextTick()
  state.reason.value = 'messages'
  state.files.value = [{ id: 1 }, { id: 2 }, { id: 3 }]
  await state.submit()
  assert.equal(posted.length, 0)
  await state.submit()
  assert.deepEqual(uploads, [1, 2, 2, 3])
  assert.deepEqual(Array.from(posted[0][1].body.attachmentIds), [1, 2, 3])
  assert.equal(state.reporting.value, false)
})

test('report update responses from an earlier account cannot show a private modal', async () => {
  const currentUser = user()
  const requests = []
  const { state } = await setupView('../src/components/shared/ReportNotices.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiGet: () => new Promise(resolve => { requests.push(resolve) }), apiRequest: async () => {} },
  })
  currentUser.value = { ...currentUser.value, id: 20 }
  await nextTick()
  requests[1]({ userId: 20, data: [{ id: 'new', status: 'resolved', message: 'New account report' }] })
  await flush()
  requests[0]({ userId: 10, data: [{ id: 'old', message: 'Private old account report' }] })
  await flush()
  assert.equal(state.active.value.id, 'new')
  assert.equal(state.notices.value.length, 1)
  state.announce({ detail: { userId: 10, title: 'Wrong account' } })
  assert.equal(state.acknowledgement.value, null)
})

test('admin report updates send the current record version and retain failed responses', async () => {
  const currentUser = ref({ id: 99, isAdmin: true, approved: true })
  const writes = []
  const { state } = await setupView('../src/views/AdminSafetyView.vue', {
    'vue-router': { useRoute: () => ({ meta: { routeName: 'admin-reports', safetySection: 'reports' } }) },
    '../composables/useCurrentUser': { currentUser },
    '../lib/api': { formatDate: () => '', apiGet: async () => ({ data: [], meta: { total: 0, page: 1 } }), apiRequest: async (path, options) => { writes.push([path, options]); throw new Error('Report changed') } },
  }, {}, { setTimeout, clearTimeout })
  state.view({ id: 'report-id', status: 'received', version: 3, response: '' })
  state.status.value = 'resolved'
  state.response.value = 'Reviewed and resolved by the team.'
  await state.save()
  assert.equal(writes[0][0], '/admin/safety/reports/report-id')
  assert.equal(writes[0][1].body.version, 3)
  assert.equal(state.selected.value.id, 'report-id')
  assert.equal(state.response.value, 'Reviewed and resolved by the team.')
  assert.equal(state.formError.value, 'Report changed')
})


test('personal review pairs and blocks use owner-scoped endpoints even for an admin', async () => {
  const currentUser = ref({ id: 99, isAdmin: true, approved: true })
  const paths = []
  const route = { meta: { routeName: 'account-reviews', safetySection: 'reviews' } }
  const { state } = await setupView('../src/views/AdminSafetyView.vue', {
    'vue-router': { useRoute: () => route },
    '../composables/useCurrentUser': { currentUser },
    '../lib/api': { formatDate: () => '', apiGet: async path => { paths.push(path); return { data: [{ id: 1, received: { rating: 5 }, given: { rating: 4 } }], meta: { total: 1, page: 1 } } }, apiRequest: async path => { paths.push(path) } },
  }, {}, { setTimeout, clearTimeout })
  await flush()
  assert.match(paths[0], /^\/me\/safety\/reviews\?/)
  assert.deepEqual(Array.from(state.columns.value, column => column.key), ['createdAt', 'name', 'campaign', 'received', 'given'])
  assert.equal(state.rows.value[0].received.rating, 5)
  assert.equal(state.rows.value[0].given.rating, 4)
  state.unblocking.value = { id: 'own-block' }
  await state.unblock()
  assert.ok(paths.includes('/me/safety/blocks/own-block'))
  assert.equal(paths.some(path => path.startsWith('/admin/')), false)
})
