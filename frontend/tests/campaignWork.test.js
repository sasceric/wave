import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { compareInboxThreads } from '../src/lib/inboxGroups.js'
import { setupView } from './setupView.js'

test('changes require a reason and a failed request preserves the shared form draft', async () => {
  const calls = []
  let fail = true
  const { state, emitted } = await setupView('../src/components/account/CampaignWorkAction.vue', {
    '../../lib/api': { apiRequest: async (url, options) => { calls.push({ url, ...options }); if (fail) throw new Error('Try again'); return { data: { id: 9, workStatus: 'changes_requested' } } }, formatDate: () => '' },
  }, { offer: { id: 9, status: 'accepted', workStatus: 'submitted', applicationId: 4 }, provider: false })
  state.open('changes')
  await state.submit()
  assert.equal(calls.length, 0)
  state.reason.value = 'Please fix the subtitles and the closing frame.'
  await state.submit()
  assert.equal(state.error.value, 'Try again')
  assert.equal(state.action.value, 'changes')
  assert.equal(state.reason.value, 'Please fix the subtitles and the closing frame.')
  fail = false
  await state.submit()
  assert.equal(calls[1].url, '/me/offers/9/work')
  assert.equal(calls[1].body.reason, state.reason.value)
  assert.equal(emitted[0][0], 'changed')
  assert.equal(state.action.value, '')
})

test('completion opens the existing review form and discards results after an account change', async () => {
  const currentUser = ref({ id: 10 })
  const props = reactive({ offer: { id: 9, status: 'accepted', applicationId: 4 }, provider: false })
  let resolve
  const { state, emitted } = await setupView('../src/components/account/CampaignWorkAction.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiRequest: () => new Promise(done => { resolve = done }), formatDate: () => '' },
  }, props)
  state.open('complete')
  const pending = state.submit()
  currentUser.value = { id: 12 }
  resolve({ data: { workStatus: 'completed' } })
  await pending
  assert.equal(emitted.length, 0)
  assert.equal(state.reviewOpen.value, false)
  state.open('complete')
  const next = state.submit()
  resolve({ data: { workStatus: 'completed' } })
  await next
  assert.equal(state.reviewOpen.value, true)
  assert.equal(emitted.length, 1)
  props.offer = { ...props.offer, workCompletedAt: '2026-10-19T12:00:00Z', workStatus: 'completed' }
  await nextTick()
  assert.equal(state.reviewOpen.value, true, 'Refreshing the same offer must keep its review modal open.')
})

test('closed campaign chat stays available only to a hired participant', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/inboxGroups': { compareInboxThreads },
    'vue-router': { useRoute: () => ({ query: {} }), useRouter: () => ({}) },
    '../lib/api': { formatDate: () => '', formatMoney: () => '', apiGet: async () => ({ data: [] }), apiRequest: async () => ({}) },
  })
  state.conversations.value = [{ id: 7, campaign: { status: 'closed' }, engagement: { offer: { status: 'accepted' } } }]
  state.selectedConversationId.value = 7
  assert.equal(state.campaignChatClosed.value, false)
  state.conversations.value[0].engagement.offer.status = 'pending'
  assert.equal(state.campaignChatClosed.value, true)
})

test('the same sidebar component submits direct work without a campaign review or finish action', async () => {
  const calls = []
  const { state, emitted } = await setupView('../src/components/account/CampaignWorkAction.vue', {
    '../../lib/api': { apiRequest: async (url, options) => { calls.push({ url, ...options }); return { data: { id: 17, workStatus: 'completed' } } }, formatDate: () => '' },
  }, { offer: { id: 17, status: 'accepted', workStatus: 'submitted', inquiryId: 8 }, provider: false, inquiry: true })
  state.open('complete')
  await state.submit()
  assert.equal(calls[0].url, '/me/inquiry-offers/17/work')
  assert.equal(calls[0].body.action, 'complete')
  assert.equal(state.reviewOpen.value, false)
  assert.equal(emitted[0][1].workStatus, 'completed')
})

test('late inquiry metadata cannot push an active chat below older conversations', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/inboxGroups': { compareInboxThreads },
    'vue-router': { useRoute: () => ({ query: {} }), useRouter: () => ({}) },
    '../lib/api': { formatDate: () => '', formatMoney: () => '', apiGet: async () => ({ data: [] }), apiRequest: async () => ({}) },
  })
  state.conversations.value = [{ id: 20, lastMessageAt: '2026-10-18T13:00:00Z', campaign: {} }]
  state.inquiries.value = [{ id: 4, canChat: true, lastMessage: 'Newest message', lastMessageAt: '2026-10-19T12:00:00Z', createdAt: '2026-01-01T12:00:00Z' }]
  state.upsertInquiry({ id: 4, canChat: true, lastMessage: 'Original inquiry', lastMessageAt: '2026-01-01T12:00:00Z' })
  assert.equal(state.threads.value[0].threadType, 'inquiry')
  assert.equal(state.threads.value[0].lastMessage, 'Newest message')
  assert.equal(state.threads.value[1].id, 20)
})
