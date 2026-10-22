import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, ref } from 'vue'
import { setupView } from './setupView.js'

const campaign = { slug: 'spring', title: 'Spring campaign', budgetMin: 300, budgetMax: 800, currency: 'BAM', company: { slug: 'brand', name: 'Brand' } }

test('voluntary campaign hiring confirms no fee but still requires a deadline and creator response', async () => {
  const calls = []
  const { state, emitted } = await setupView('../src/components/account/CampaignHireAction.vue', {
    '../../lib/api': { apiRequest: async (path, options) => { calls.push({ path, body: options.body }); return { data: { id: 99, amount: 0, status: 'pending' } } } },
  }, { conversationId: 9, campaign: { ...campaign, compensationType: 'voluntary' }, disabled: false })
  state.start()
  assert.equal(state.amount.value, 0)
  assert.equal(state.message.value, 'compensation.defaultHireMessage')
  await state.submit()
  assert.equal(calls.length, 0)
  state.dueDate.value = '2099-01-20'
  state.message.value = 'Our agreed voluntary collaboration without a fee.'
  state.amount.value = 300
  await state.submit()
  assert.equal(calls[0].body.amount, 0)
  assert.equal(emitted[0][1].status, 'pending')
})

test('no-fee labels are explicit and never inferred just from a missing or zero budget', async () => {
  const formatter = amount => `${amount} KM`
  const { state } = await setupView('../src/components/campaigns/CampaignCompensation.vue', {
    '../../lib/api': { formatMoney: formatter },
  }, { campaign: { ...campaign, compensationType: 'voluntary' }, amount: 0 })
  assert.equal(state.label.value, 'compensation.noFee')
  const paid = await setupView('../src/components/campaigns/CampaignCompensation.vue', {
    '../../lib/api': { formatMoney: formatter },
  }, { campaign: { budgetMin: 0, budgetMax: 0, currency: 'BAM' }, amount: null })
  assert.equal(paid.state.label.value, '0 KM – 0 KM')
})

test('hiring confirms a negotiated amount outside the advertised budget and retains failed drafts', async () => {
  const currentUser = ref({ id: 10, accountType: 'company' })
  const calls = []
  let fail = true
  const { state, emitted } = await setupView('../src/components/account/CampaignHireAction.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiRequest: async (path, options) => { calls.push({ path, body: options.body }); if (fail) throw new Error('Try again'); return { data: { id: 17, amount: options.body.amount, status: 'pending' } } } },
  }, { conversationId: 9, campaign, creatorName: 'Amina', disabled: false })
  state.start()
  state.amount.value = 50
  state.dueDate.value = '2099-01-20'
  state.message.value = 'Our negotiated campaign terms.'
  await state.submit()
  assert.equal(state.open.value, true)
  assert.equal(state.error.value, 'Try again')
  assert.equal(state.amount.value, 50)
  fail = false
  await state.submit()
  assert.equal(calls[1].path, '/company/conversations/9/hire')
  assert.equal(calls[1].body.amount, 50)
  assert.equal(calls[1].body.dueDate, '2099-01-20')
  assert.equal(emitted[0][0], 'offered')
  assert.equal(emitted[0][1].status, 'pending')
  assert.equal(state.open.value, false)
})

test('an invited creator sees their invitation and cannot submit another application', async () => {
  const calls = []
  const { state } = await setupView('../src/views/CampaignDetailView.vue', {
    'vue-router': { useRoute: () => ({ params: { slug: 'spring' }, query: {}, meta: {} }) },
    '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: () => {} },
    '../routePaths': { localizedPath: () => '/kompanije/brand' },
    '../lib/api': { apiGet: async path => {
      if (path === '/auth/session') return { data: { id: 1, accountType: 'creator', approved: true, emailVerified: true } }
      if (path === '/me/applications') return { data: [] }
      if (path === '/me/invitations') return { data: [{ id: 2, status: 'accepted', conversationId: 9, campaign }] }
      return { data: campaign }
    }, apiRequest: async path => { calls.push(path) }, formatDate: () => '', formatMoney: () => '' },
  })
  await state.loadCampaign()
  await nextTick()
  assert.equal(state.invitation.value.conversationId, 9)
  assert.equal(state.alreadyApplied.value, false)
  await state.applyToCampaign()
  assert.equal(calls.length, 0)
})

test('campaign creator rows distinguish invitations and confirmed hires', async () => {
  const { state } = await setupView('../src/components/account/CompanyCampaignDetail.vue', {
    '../../lib/api': { formatDate: () => '', formatMoney: () => '' },
    '../../lib/chatTime': { createChatTimeFormatter: () => ({ ago: () => '' }) },
  }, { campaign, applications: [] })
  assert.equal(state.statusLabel({ status: 'invited' }), 'hiring.invited')
  assert.equal(state.statusLabel({ status: 'accepted' }), 'hiring.hired')
})


test('hiring starts without assumed terms and requires a delivery deadline', async () => {
  const calls = []
  const { state } = await setupView('../src/components/account/CampaignHireAction.vue', {
    '../../lib/api': { apiRequest: async (...args) => { calls.push(args) } },
  }, { conversationId: 9, campaign, disabled: false })
  state.start()
  assert.equal(state.amount.value, '')
  assert.equal(state.dueDate.value, '')
  state.amount.value = 300
  state.message.value = 'The agreed campaign scope.'
  await state.submit()
  assert.equal(calls.length, 0)
  state.dueDate.value = '2000-01-01'
  await state.submit()
  assert.equal(calls.length, 0)
})


test('the shared hire form sends direct-inquiry terms to its own endpoint', async () => {
  const requests = []
  const { state } = await setupView('../src/components/account/CampaignHireAction.vue', {
    '../../lib/api': { apiRequest: async (path, options) => { requests.push({ path, ...options }); return { data: { id: 42, status: 'pending' } } } },
  }, { inquiryId: 12, campaign: { title: 'Direct collaboration', currency: 'BAM' }, disabled: false })
  state.start()
  state.amount.value = 400
  state.currency.value = 'EUR'
  state.dueDate.value = '2099-01-20'
  state.message.value = 'Our agreed direct collaboration terms.'
  await state.submit()
  assert.equal(requests[0].path, '/company/inquiries/12/hire')
  assert.equal(requests[0].body.currency, 'EUR')
  assert.equal(requests[0].body.dueDate, '2099-01-20')
})
