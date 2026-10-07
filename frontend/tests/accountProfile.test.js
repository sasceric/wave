import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

async function setupAccount({ saveError = null, accountType = 'company' } = {}) {
  const requests = []
  const scrolls = []
  let blurred = false
  const account = {
    id: 1, accountType, approved: false, isAdmin: false,
    profileComplete: true, emailVerified: true,
    phone: '+38761123456', city: 'Sarajevo', countryCode: 'BA',
    profile: {
      name: 'Test company', industry: 'Software', about: 'Saved description',
      socialProfiles: [{ platform: 'TikTok', followers: 100 }],
      ...(accountType === 'creator' ? {
        displayName: 'Test creator', category: 'Travel', categories: ['Travel'],
        bio: 'Saved bio', tagline: '', tags: ['Nature', 'Food, drinks'],
        socialProfiles: [], portfolio: [], packages: [], faqs: [],
      } : {}),
    },
  }
  const view = await setupView('../src/views/AccountView.vue', {
    '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
    '../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({ bookmarks: ref([]), loadCampaignBookmarks: async () => {} }) },
    '../lib/phoneNumbers': { formatInternationalPhoneNumber: (phone) => phone },
    '../lib/api': {
      apiGet: async () => ({ data: account }),
      apiRequest: async (path, options) => {
        requests.push({ path, ...options })
        if (saveError) throw new Error(saveError)
        account.profile = { ...account.profile, ...options.body }
        return { data: account }
      },
      formatDate: () => '', formatMoney: () => '',
    },
  }, {}, { structuredClone })
  view.windowTarget.scrollTo = (options) => scrolls.push(options)
  view.documentTarget.activeElement = { blur: () => { blurred = true } }
  await view.state.loadDashboard()
  return { ...view, requests, scrolls, wasBlurred: () => blurred }
}

test('Cancel restores nested profile details, tags and phone country without saving', async () => {
  const { state, requests } = await setupAccount()
  state.tags.value = ['original']
  state.profilePhoneCountry.value = 'BA'
  assert.equal(state.editingProfile.value, false)
  state.startProfileEdit()
  state.profile.value.name = 'Unsaved company'
  state.profile.value.socialProfiles[0].followers = 999
  state.tags.value = ['unsaved']
  state.profilePhoneCountry.value = 'HR'
  state.cancelProfileEdit()
  assert.equal(state.editingProfile.value, false)
  assert.equal(state.profile.value.name, 'Test company')
  assert.equal(state.profile.value.socialProfiles[0].followers, 100)
  assert.deepEqual(Array.from(state.tags.value), ['original'])
  assert.equal(state.profilePhoneCountry.value, 'BA')
  assert.equal(requests.length, 0)
})

test('editing a section selects its tab and repeated edit actions retain the original Cancel snapshot', async () => {
  const { state, requests } = await setupAccount()
  state.startProfileEdit('portfolio')
  assert.equal(state.accountTab.value, 'portfolio')
  state.profile.value.name = 'Unsaved company'
  state.startProfileEdit('about')
  assert.equal(state.accountTab.value, 'about')
  state.cancelProfileEdit()
  assert.equal(state.profile.value.name, 'Test company')
  assert.equal(requests.length, 0)
})

test('Save uses the existing profile API and returns to saved details', async () => {
  const { state, requests } = await setupAccount()
  state.startProfileEdit()
  state.profile.value.name = 'Updated company'
  await state.saveProfile()
  assert.equal(requests.length, 1)
  assert.equal(requests[0].path, '/me/profile')
  assert.equal(requests[0].method, 'PUT')
  assert.equal(requests[0].body.name, 'Updated company')
  assert.equal(state.profile.value.name, 'Updated company')
  assert.equal(state.editingProfile.value, false)
})

test('creator tags load and save as separate values, preserving commas inside a tag', async () => {
  const { state, requests } = await setupAccount({ accountType: 'creator' })
  assert.deepEqual(Array.from(state.tags.value), ['Nature', 'Food, drinks'])
  state.startProfileEdit()
  state.tags.value = ['Nature', 'Food, drinks', 'Travel']
  await state.saveProfile()
  assert.equal(requests.length, 1)
  assert.deepEqual(Array.from(requests[0].body.tags), ['Nature', 'Food, drinks', 'Travel'])
  assert.deepEqual(Array.from(state.tags.value), ['Nature', 'Food, drinks', 'Travel'])
  assert.equal(state.editingProfile.value, false)
})

test('a failed Save leaves the form and draft available for correction', async () => {
  const { state } = await setupAccount({ saveError: 'Please try again' })
  state.startProfileEdit()
  state.profile.value.name = 'Updated company'
  await state.saveProfile()
  assert.equal(state.editingProfile.value, true)
  assert.equal(state.profile.value.name, 'Updated company')
  assert.equal(state.error.value, 'Please try again')
  state.cancelProfileEdit()
  assert.equal(state.profile.value.name, 'Test company')
})

test('offers resolve chats by their application, preserving unrelated conversations and missing chats', async () => {
  const { state } = await setupAccount({ accountType: 'creator' })
  state.applications.value = [
    { id: 12, conversationId: 78 },
    { id: 13, conversationId: 79 },
  ]
  state.offers.value = [
    { id: 1, applicationId: 13 },
    { id: 2, applicationId: 12 },
    { id: 3, applicationId: 99 },
  ]
  assert.deepEqual(Array.from(state.offersWithConversations.value, (offer) => offer.conversationId), [79, 78, null])
})

test('login clears form focus and resets the page without a smooth scroll', async () => {
  const { state, scrolls, wasBlurred } = await setupAccount()
  await state.handleAuthenticated()
  assert.equal(wasBlurred(), true)
  assert.equal(scrolls.length, 1)
  assert.equal(scrolls[0].top, 0)
  assert.equal(scrolls[0].behavior, 'instant')
  assert.equal(state.editingProfile.value, false)
})

test('company Save sends multiple industries and its cover and logo changes together', async () => {
  const { state, requests } = await setupAccount()
  let committed = 0
  state.startProfileEdit()
  state.profile.value.industries = ['Software', 'Technology']
  state.profileImageField.value = { prepareSave: async () => ({ mediaId: 11, url: '/logo', uploadedMediaId: 11 }), commit: () => { committed++ } }
  state.companyCoverField.value = { prepareSave: async () => ({ mediaId: 12, url: '/cover', uploadedMediaId: 12 }), commit: () => { committed++ } }
  await state.saveProfile()
  assert.deepEqual(requests[0].body.industries, ['Software', 'Technology'])
  assert.equal(requests[0].body.logoMediaId, 11)
  assert.equal(requests[0].body.coverMediaId, 12)
  assert.equal(committed, 2)
})

test('failed company Save rolls back both staged uploads', async () => {
  const { state } = await setupAccount({ saveError: 'Failed save' })
  const rolledBack = []
  state.startProfileEdit()
  for (const [field, id] of [[state.profileImageField, 11], [state.companyCoverField, 12]]) {
    field.value = { prepareSave: async () => ({ mediaId: id, url: '/image', uploadedMediaId: id }), rollback: async (change) => { rolledBack.push(change.uploadedMediaId) } }
  }
  await state.saveProfile()
  assert.deepEqual(rolledBack, [11, 12])
  assert.equal(state.editingProfile.value, true)
})

test('campaign Save sends separate city, country, areas and deliverables', async () => {
  const { state, requests } = await setupAccount()
  state.campaignForm.value.city = 'Zagreb'
  state.campaignForm.value.countryCode = 'HR'
  state.campaignForm.value.categories = ['Food', 'Travel']
  state.campaignForm.value.deliverables = ['1 video', '3 photos']
  await state.saveCampaign()
  assert.equal(requests[0].body.city, 'Zagreb')
  assert.equal(requests[0].body.countryCode, 'HR')
  assert.deepEqual(Array.from(requests[0].body.categories), ['Food', 'Travel'])
  assert.deepEqual(Array.from(requests[0].body.deliverables), ['1 video', '3 photos'])
  assert.ok(!('location' in requests[0].body))
})
