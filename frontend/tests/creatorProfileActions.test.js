import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

async function profileView(user = null) {
  const requests = []
  const scrolls = []
  const profile = { id: 7, slug: 'amina', displayName: 'Amina', canReceiveCampaignInvitations: true, rating: {}, socialProfiles: [], portfolio: [], packages: [], faqs: [], categories: [], tags: [] }
  const view = await setupView('../src/views/CreatorProfileView.vue', {
    'vue-router': { useRoute: () => ({ params: { slug: 'amina' }, meta: {}, query: {} }) },
    '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
    '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: () => {} },
    '../routePaths': { localizedPath: () => '/kreatori/amina' },
    '../lib/api': {
      apiGet: async path => {
        requests.push({ path })
        if (path === '/creators/amina') return { data: profile }
        if (path === '/auth/session') return { data: user }
        if (path === '/me/campaigns') return { data: [
          { slug: 'open-campaign', title: 'Open campaign', status: 'open' },
          { slug: 'finished-campaign', title: 'Finished campaign', status: 'finished' },
        ] }
        throw new Error(`Unexpected request ${path}`)
      },
      apiRequest: async (path, options) => { requests.push({ path, options }); return {} },
      formatDate: () => '', formatMoney: () => '',
    },
  })
  view.documentTarget.getElementById = id => ({ scrollIntoView: options => scrolls.push({ id, options }) })
  await view.state.loadCreator()
  return { ...view, requests, scrolls }
}

test('opening a company invitation loads campaigns only when the modal is opened', async () => {
  const { state, requests } = await profileView({ accountType: 'company', approved: true, emailVerified: true, profileComplete: true })
  assert.equal(requests.some(request => request.path === '/me/campaigns'), false)
  await state.openCampaignInvitation()
  assert.equal(state.invitationOpen.value, true)
  assert.deepEqual(state.inviteCampaigns.value.map(campaign => campaign.slug), ['open-campaign'])
  state.inviteForm.value.message = 'Poziv za našu kampanju.'
  await state.sendCampaignInvitation()
  assert.equal(state.inviteSubmitted.value, true)
  assert.equal(requests.at(-1).path, '/company/campaigns/open-campaign/invitations')
  assert.equal(requests.at(-1).options.body.creatorId, 7)
})

test('guests and creators can open the invitation explanation without company API access', async () => {
  for (const user of [null, { accountType: 'creator', approved: true, emailVerified: true }]) {
    const { state, requests } = await profileView(user)
    await state.openCampaignInvitation()
    await state.sendCampaignInvitation()
    assert.equal(state.invitationOpen.value, true)
    assert.equal(requests.some(request => request.path === '/me/campaigns' || request.options), false)
  }
})

test('profile section links scroll to the requested content and select its tab', async () => {
  const { state, documentTarget } = await setupView('../src/components/shared/ProfileTabs.vue', {}, { sections: true, items: [], modelValue: '' })
  const scrolls = []
  documentTarget.getElementById = id => ({ scrollIntoView: options => scrolls.push({ id, options }) })
  state.choose({ key: 'creator-reviews' })
  assert.equal(scrolls[0].id, 'creator-reviews')
  assert.equal(scrolls[0].options.block, 'start')
})

test('overflowing tabs scroll horizontally without trapping the page at the end', async () => {
  const { state } = await setupView('../src/components/shared/ProfileTabs.vue', {}, { sections: true, items: [] })
  const tabs = { scrollWidth: 600, clientWidth: 300, scrollLeft: 0 }
  let prevented = 0
  const event = { currentTarget: tabs, deltaX: 0, deltaY: 70, preventDefault: () => { prevented++ } }
  state.scrollTabs(event)
  assert.equal(tabs.scrollLeft, 70)
  assert.equal(prevented, 1)
  tabs.scrollLeft = 300
  state.scrollTabs(event)
  assert.equal(prevented, 1)
})

test('public presentation uses the largest known channel and omits private work', async () => {
  const { state } = await setupView('../src/components/creators/CreatorPresentationPreview.vue', {}, {
    profile: {
      socialProfiles: [{ platform: 'TikTok', followers: 18000 }, { platform: 'Instagram', followers: 24800 }, { platform: 'Spotify', followers: null }],
      portfolio: [{ id: 'public', type: 'image', url: '/work.webp' }, { id: 'private', type: 'image', url: '/private.webp', publicPermission: false }],
      rating: { average: 4.9 },
    },
  })
  assert.equal(state.channel.value.platform, 'Instagram')
  assert.equal(state.number(state.channel.value.followers), '24.800')
  assert.equal(state.images.value.length, 1)
  assert.equal(state.images.value[0].id, 'public')
})


test('the initial direct inquiry sends only package selection and message, without proposed money terms', async () => {
  const { state, requests } = await profileView({ accountType: 'company', approved: true, emailVerified: true, profileComplete: true })
  state.openRequestForm(null, true)
  state.requestForm.value.message = 'Let us discuss a video collaboration in chat.'
  await state.submitRequest()
  const request = requests.find(request => request.options)
  assert.equal(request.path, '/creators/amina/inquiries')
  assert.equal(request.options.body.other, true)
  assert.equal('proposedAmount' in request.options.body, false)
  assert.equal('currency' in request.options.body, false)
})
