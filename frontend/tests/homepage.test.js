import assert from 'node:assert/strict'
import test from 'node:test'
import { reactive, ref } from 'vue'
import { setupView } from './setupView.js'
import * as searchQuery from '../src/lib/searchQuery.js'

// Values created inside the component's VM context have a different Object prototype.
const plain = value => JSON.parse(JSON.stringify(value))

async function homeSetup(user, unread = 0) {
  const calls = []
  const currentUser = ref(user)
  const setup = await setupView('../src/views/HomeView.vue', {
    '../composables/useCurrentUser': { currentUser },
    '../composables/useUnreadMessages': { unreadMessageCount: ref(unread) },
    '../lib/api': { apiGet: async (...args) => { calls.push(args); return { data: { creatorMode: 'featured', creators: [{ id: 1 }], campaigns: [{ id: 2 }] } } } },
    '../routePaths': { localizedPath: (name, locale) => `/${locale}/${name}` },
  })
  return { ...setup, calls }
}

test('homepage loads the existing homepage payload and keeps loading and error state', async () => {
  const { state, calls } = await homeSetup(null)
  await state.loadHome()
  assert.deepEqual(calls[0], ['/homepage'])
  assert.equal(state.loading.value, false)
  assert.equal(state.creatorMode.value, 'featured')
  assert.equal(state.creators.value.length, 1)
  assert.equal(state.campaigns.value.length, 1)
})

test('guest entry points keep the registration account type and campaign return path', async () => {
  const { state } = await homeSetup(null)
  assert.deepEqual(plain(state.registerCreator), { name: 'register', query: { accountType: 'creator' } })
  assert.deepEqual(plain(state.registerCompany.value), { name: 'register', query: { accountType: 'company', returnTo: '/bs/account-campaign-create' } })
})

test('signed-in shortcuts follow the account type and show unread messages', async () => {
  const creator = await homeSetup({ id: 1, accountType: 'creator', profile: { displayName: 'Amila' } }, 3)
  assert.deepEqual(plain(creator.state.shortcuts.value.map(item => item.key)), ['campaigns', 'applications', 'messages', 'profile'])
  assert.equal(creator.state.shortcuts.value.find(item => item.key === 'messages').badge, 3)
  assert.equal(creator.state.greetingName.value, 'Amila')

  const company = await homeSetup({ id: 2, accountType: 'company', profile: { name: 'Studio' } })
  assert.deepEqual(plain(company.state.shortcuts.value.map(item => item.key)), ['new', 'campaigns', 'messages', 'creators'])
  assert.deepEqual(plain(company.state.shortcuts.value[0].to), { name: 'account-campaign-create' })
})

test('the "how it works" steps switch between creator and brand copy', async () => {
  const { state } = await homeSetup(null)
  assert.equal(state.steps.value[0].title, 'home.creatorStep1Title')
  state.stepsAudience.value = 'brand'
  assert.equal(state.steps.value[2].description, 'home.brandStep3Text')
})

test('the hero search never collapses into the mobile header icon', async () => {
  const route = reactive({ query: {}, params: {}, meta: {}, fullPath: '/' })
  const navigations = []
  const { state } = await setupView('../src/components/shared/HeaderCreatorSearch.vue', {
    '../../composables/useCurrentUser': { currentUser: ref(null) },
    '../../lib/api': { apiGet: async () => ({ data: {}, meta: { counts: {} } }), apiRequest: async () => ({}), formatMoney: String },
    '../../routePaths': { localizedRouteName: (name, language) => `${name}-${language}` },
    '../../lib/searchQuery': searchQuery,
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async value => navigations.push(value) }) },
    'vue-i18n': { useI18n: () => ({ locale: ref('bs'), t: key => key }) },
  }, { variant: 'hero' })
  state.mobile.value = true
  assert.equal(state.collapsible.value, false)
  state.query.value = 'ljepota'
  await state.handleSubmit()
  assert.equal(state.expanded.value, false)
  assert.deepEqual(plain(navigations[0]), { name: 'search-bs', query: { q: 'ljepota' } })
})
