import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref, nextTick } from 'vue'
import { mergeCampaignActivity, campaignActivityStage } from '../src/lib/campaignActivity.js'
import { setupView } from './setupView.js'

const campaign = { id: 10, slug: 'demo', title: 'Demo campaign', status: 'open', currency: 'BAM' }

test('campaign activity merges invitations, offers and applications into one row with original action IDs', () => {
  const rows = mergeCampaignActivity([{ id: 100, status: 'offered', campaign, createdAt: '2026-10-01', conversationId: 20 }], [{ id: 200, status: 'accepted', campaign, conversationId: 20 }], [{ id: 300, applicationId: 100, status: 'pending', campaign, amount: 900, createdAt: '2026-10-03' }])
  assert.equal(rows.length, 1)
  assert.equal(rows[0].id, 100)
  assert.equal(rows[0].offer.id, 300)
  assert.equal(rows[0].invitation.id, 200)
  assert.equal(rows[0].conversationId, 20)
  assert.equal(campaignActivityStage(rows[0]), 'offered')
  assert.equal(campaignActivityStage({ ...rows[0], offer: { ...rows[0].offer, status: 'accepted' } }), 'hired')
})

test('finished and canceled collaborations override acceptance while closed campaigns keep active hires', () => {
  const hired = { source: 'application', status: 'accepted', offer: { status: 'accepted' }, campaign }
  assert.equal(campaignActivityStage({ ...hired, campaign: { ...campaign, status: 'finished' } }), 'finished')
  assert.equal(campaignActivityStage({ ...hired, campaign: { ...campaign, status: 'closed' } }), 'hired')
  assert.equal(campaignActivityStage({ ...hired, offer: { status: 'cancelled' }, campaign: { ...campaign, status: 'finished' } }), 'cancelled')
  assert.equal(campaignActivityStage({ ...hired, campaign: { ...campaign, status: 'cancelled' } }), 'cancelled')
  assert.equal(campaignActivityStage({ status: 'pending', campaign: { ...campaign, status: 'closed' } }), 'closed')
})

test('accepted invitations represent discussion, never a hired creator', () => {
  const rows = mergeCampaignActivity([], [{ id: 1, status: 'accepted', campaign, conversationId: 9 }], [])
  assert.equal(campaignActivityStage(rows[0]), 'invited')
  assert.equal(rows[0].invitation.id, 1)
  assert.equal(rows[0].conversationId, 9)
})

test('cancellation keeps the modal on failure and drops stale responses after an account change', async () => {
  const currentUser = ref({ id: 10 })
  let finish
  let fail = true
  const { state, emitted } = await setupView('../src/components/account/CampaignCancellationAction.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiRequest: async path => {
      assert.equal(path, '/me/offers/300/cancel')
      if (fail) throw new Error('Try again')
      return new Promise(resolve => { finish = resolve })
    } },
  }, { offerId: 300, autoOpen: true, hideTrigger: true })
  await state.cancel()
  assert.equal(state.open.value, true)
  assert.equal(state.error.value, 'Try again')
  fail = false
  const pending = state.cancel()
  await nextTick()
  currentUser.value = { id: 11 }
  await nextTick()
  finish({ data: { id: 300, status: 'cancelled' } })
  await pending
  assert.equal(emitted.some(([event]) => event === 'cancelled'), false)
})

test('unified activity exposes review eligibility, compact responses and distinct completion states', async () => {
  const items = [
    { id: 1, source: 'application', status: 'accepted', campaign: { ...campaign, status: 'finished' }, offer: { id: 5, status: 'accepted', amount: 900 } },
    { id: 2, source: 'application', status: 'offered', campaign, offer: { id: 6, status: 'pending', amount: 900 } },
    { id: 'invitation-3', source: 'invitation', status: 'accepted', campaign },
  ]
  const { state, emitted } = await setupView('../src/components/account/CreatorCampaignActivity.vue', {
    '../../lib/campaignActivity': { campaignActivityStage },
    '../../composables/useCurrentUser': { currentUser: ref({ id: 10 }) },
    '../../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({}) },
  }, { kind: 'activity', items, canRespond: true })
  assert.equal(state.statusLabel(items[0]), 'account.finished')
  assert.equal(state.statusLabel(items[2]), 'hiring.invited')
  assert.equal(state.canReview(items[0]), true)
  assert.equal(state.canCancel(items[0]), false)
  assert.equal(state.canAnswer(items[2]), false)
  state.requestResponse(items[1], 'accept')
  assert.equal(emitted.length, 0)
  assert.equal(state.pendingResponse.value.item.offer.amount, 900)
  state.confirmResponse()
  assert.equal(emitted[0][0], 'respond')
  assert.equal(emitted[0][1].offer.id, 6)
})
