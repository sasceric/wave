import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

async function setupOffers({ bookmarkError = null } = {}) {
  const toggled = []
  const props = reactive({
    items: [{ id: 3, conversationId: 42, status: 'pending', campaign: { slug: 'summer' } }],
    kind: 'offers',
    canRespond: true,
    responding: false,
  })
  const view = await setupView('../src/components/account/CreatorCampaignActivity.vue', {
    '../../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({
      isCampaignBookmarked: () => false,
      toggleCampaignBookmark: async (campaign) => {
        toggled.push(campaign.slug)
        if (bookmarkError) throw new Error(bookmarkError)
      },
    }) },
  }, props)
  view.documentTarget.body = { style: { overflow: 'auto' } }
  const dialog = {
    open: false,
    showModal() { this.open = true },
    close() { this.open = false },
  }
  view.state.dialog.value = dialog
  return { ...view, props, dialog, toggled }
}

test('offer chat links target the exact campaign conversation', async () => {
  const { state, props } = await setupOffers()
  const target = state.chatRoute(props.items[0])
  assert.equal(target.name, 'messages')
  assert.equal(target.query.conversation, 42)
})

test('campaign drawer opens the offered brief and restores scrolling when dismissed', async () => {
  const { state, props, dialog, documentTarget } = await setupOffers()
  await state.openCampaign(props.items[0])
  assert.equal(dialog.open, true)
  assert.equal(state.campaign.value.slug, 'summer')
  assert.equal(documentTarget.body.style.overflow, 'hidden')
  state.closeCampaign()
  assert.equal(dialog.open, false)
  assert.equal(state.selectedItem.value, null)
  assert.equal(documentTarget.body.style.overflow, 'auto')
  state.closeCampaign()
  assert.equal(documentTarget.body.style.overflow, 'auto')
})

test('bookmark failures keep the campaign drawer open with an inline error', async () => {
  const { state, props, dialog, toggled } = await setupOffers({ bookmarkError: 'Campaign unavailable' })
  await state.openCampaign(props.items[0])
  await state.toggleBookmark()
  assert.deepEqual(toggled, ['summer'])
  assert.equal(state.bookmarkError.value, 'Campaign unavailable')
  assert.equal(state.bookmarkBusy.value, false)
  assert.equal(dialog.open, true)
})

test('closing before the drawer renders does not open a stale campaign', async () => {
  const { state, props, dialog } = await setupOffers()
  const opening = state.openCampaign(props.items[0])
  state.closeCampaign()
  await opening
  await nextTick()
  assert.equal(dialog.open, false)
})

test('applications paginate in batches of ten and clamp the page when entries disappear', async () => {
  const { state, props } = await setupOffers()
  props.kind = 'applications'
  props.items = Array.from({ length: 23 }, (_, index) => ({ id: index + 1, status: 'pending' }))
  await nextTick()
  assert.equal(state.pageItems.value.length, 10)
  assert.equal(state.statusLabel(props.items[0]), 'account.pending')
  state.page.value = 2
  assert.deepEqual(Array.from(state.pageItems.value, (item) => item.id), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
  state.page.value = 3
  assert.equal(state.pageItems.value.length, 3)
  props.items = props.items.slice(0, 8)
  await nextTick()
  assert.equal(state.page.value, 1)
  assert.equal(state.pageItems.value.length, 8)
})

test('status filters and search apply before pagination and reset the current page', async () => {
  const { state, props } = await setupOffers()
  props.items = Array.from({ length: 23 }, (_, index) => ({
    id: index + 1, status: index < 12 ? 'pending' : 'accepted',
    campaign: { title: `Summer ${index}`, summary: 'A brief', company: { name: index < 12 ? 'Natura' : 'Field Notes' } },
  }))
  state.page.value = 2
  state.statusFilter.value = 'accepted'
  await nextTick()
  assert.equal(state.page.value, 1)
  assert.equal(state.filteredItems.value.length, 11)
  state.query.value = 'FIELD NOTES'
  await nextTick()
  assert.equal(state.filteredItems.value.length, 11)
  state.query.value = 'Natura'
  await nextTick()
  assert.equal(state.filteredItems.value.length, 0)
  state.statusFilter.value = 'all'
  await nextTick()
  assert.equal(state.filteredItems.value.length, 12)
})

test('inquiries resolve their own chat and counterpart without inventing a campaign', async () => {
  const { state, props } = await setupOffers()
  props.kind = 'inquiries'
  const inquiry = { id: 23, role: 'company', status: 'accepted', canChat: true, creator: { displayName: 'Creator', avatarUrl: '/avatar' }, company: { name: 'Brand' }, proposedAmount: null, listedPrice: null }
  props.items = [inquiry]
  assert.equal(state.counterpart(inquiry).name, 'Creator')
  assert.equal(state.hasChat(inquiry), true)
  assert.equal(state.chatRoute(inquiry).query.inquiry, 23)
  assert.equal(state.chatRoute(inquiry).query.conversation, undefined)
  assert.equal(state.itemBudget(inquiry), 'creatorProfile.priceByAgreement')
  await state.openCampaign(inquiry)
  assert.equal(state.campaign.value, undefined)
  assert.equal(state.selectedItem.value.id, 23)
})

test('finished campaigns preserve hired and rejected application outcomes in activity filters', async () => {
  const { state, props } = await setupOffers()
  props.kind = 'applications'
  props.items = [
    { id: 1, status: 'accepted', campaign: { status: 'finished', company: { name: 'Brand' } } },
    { id: 2, status: 'rejected', campaign: { status: 'finished', company: { name: 'Brand' } } },
  ]
  assert.equal(state.statusLabel(props.items[0]), 'account.accepted')
  assert.equal(state.statusLabel(props.items[1]), 'account.rejected')
  state.statusFilter.value = 'accepted'
  assert.equal(state.filteredItems.value.length, 1)
  assert.equal(state.filteredItems.value[0].id, 1)
  state.statusFilter.value = 'rejected'
  assert.equal(state.filteredItems.value.length, 1)
  assert.equal(state.filteredItems.value[0].id, 2)
})

test('invitations separate declined and expired invitations while preserving accepted chats', async () => {
  const { state, props } = await setupOffers()
  props.kind = 'invitations'
  const campaign = { status: 'open', closesAt: '2100-11-12', channels: ['Instagram'], company: { name: 'Natura' } }
  props.items = [
    { id: 1, status: 'pending', campaign },
    { id: 2, status: 'accepted', conversationId: 56, campaign: { ...campaign, status: 'finished' } },
    { id: 3, status: 'declined', campaign },
    { id: 4, status: 'pending', campaign: { ...campaign, closesAt: '2000-01-01' } },
    { id: 5, status: 'pending', campaign: { ...campaign, status: 'closed' } },
  ]
  assert.deepEqual(Array.from(props.items, item => state.statusGroup(item)), ['pending', 'accepted', 'rejected', 'expired', 'expired'])
  assert.equal(state.statusLabel(props.items[2]), 'account.rejected')
  assert.equal(state.statusLabel(props.items[3]), 'account.invitationExpired')
  assert.equal(state.chatRoute(props.items[1]).query.conversation, 56)
  assert.equal(state.hasChat(props.items[0]), false)
  assert.equal(state.titleKey.value, 'account.campaignInvitations')
  assert.deepEqual(Array.from(state.statusFilters.value, filter => filter.count), [5, 1, 1, 1, 2])
})

test('invitation search and filters apply across ten-item pages', async () => {
  const { state, props } = await setupOffers()
  props.kind = 'invitations'
  props.items = Array.from({ length: 23 }, (_, index) => ({
    id: index + 1, status: index < 12 ? 'pending' : 'accepted',
    campaign: { status: 'open', closesAt: '2100-01-01', title: `Campaign ${index}`, summary: 'A brief', channels: ['YouTube'], categories: ['Travel'], company: { name: 'Natura' } },
  }))
  await nextTick()
  state.page.value = 3
  assert.deepEqual(Array.from(state.pageItems.value, item => item.id), [21, 22, 23])
  state.query.value = 'youtube'
  await nextTick()
  assert.equal(state.page.value, 1)
  assert.equal(state.filteredItems.value.length, 23)
  state.statusFilter.value = 'accepted'
  await nextTick()
  assert.equal(state.filteredItems.value.length, 11)
  state.query.value = 'missing'
  await nextTick()
  assert.equal(state.filteredItems.value.length, 0)
})
