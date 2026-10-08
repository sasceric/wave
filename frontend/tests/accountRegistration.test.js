import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import { ref } from 'vue'
import { setupView } from './setupView.js'

function account(id, emailVerified) {
  return {
    id, emailVerified, approved: false, profileComplete: true, accountType: 'creator',
    phone: '+38761123456', city: 'Sarajevo', countryCode: 'BA',
    profile: { displayName: 'Test Creator', categories: ['Travel'], socialProfiles: [], tags: [] },
  }
}

test('password registration passes its unverified account to the account screen', async () => {
  const registered = account(2, false)
  const requests = []
  const view = await setupView('../src/components/account/AccountAccessPanel.vue', {
    '../../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), industries: ref([]), error: ref('') }) },
    '../../lib/phoneNumbers': { formatInternationalPhoneNumber: (phone) => phone },
    '../../lib/api': { apiRequest: async (path) => { requests.push(path); return { data: registered } } },
  })
  view.state.mode.value = 'register'
  Object.assign(view.state.form, {
    country: 'BA', city: 'Sarajevo', phone: '+38761123456',
    email: 'recreated@example.test', password: 'a-long-passphrase-for-wave',
    firstName: 'Test', lastName: 'Creator',
  })
  await view.state.submit()
  assert.deepEqual(requests, ['/auth/register'])
  assert.equal(view.emitted[0][0], 'authenticated')
  assert.equal(view.emitted[0][1], registered)
  assert.equal(view.emitted[0][1].emailVerified, false)
})

for (const oldRequestFails of [false, true]) {
  test(`a late ${oldRequestFails ? 'failed' : 'verified'} account load cannot overwrite password registration`, async () => {
    let finishOldRequest
    const oldRequest = new Promise((resolve, reject) => {
      finishOldRequest = () => oldRequestFails ? reject(Object.assign(new Error('Old session'), { status: 401 })) : resolve({ data: account(1, true) })
    })
    const view = await setupView('../src/views/AccountView.vue', {
      '../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
      '../composables/useCampaignBookmarks': { useCampaignBookmarks: () => ({ bookmarks: ref([]), loadCampaignBookmarks: async () => {} }) },
      '../lib/api': { apiGet: async () => oldRequest, apiRequest: async () => {}, formatDate: () => '', formatMoney: () => '' },
    }, {}, { structuredClone })
    view.windowTarget.scrollTo = () => {}
    const oldLoad = view.state.loadDashboard()
    await view.state.handleAuthenticated(account(2, false))
    finishOldRequest()
    await oldLoad
    assert.equal(view.state.user.value.id, 2)
    assert.equal(view.state.user.value.emailVerified, false)
    assert.equal(view.currentUser.value.id, 2)
    assert.equal(view.state.error.value, '')
    assert.equal(view.state.dashboardReady.value, true)
  })
}

test('an older shared session load cannot replace a newly registered account', async () => {
  let finishOldRequest
  const context = vm.createContext({})
  const source = await readFile(new URL('../src/composables/useCurrentUser.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link((specifier) => {
    const values = specifier === 'vue' ? { ref } : {
      apiGet: () => new Promise((resolve) => { finishOldRequest = resolve }),
    }
    return new vm.SyntheticModule(Object.keys(values), function () {
      for (const [key, value] of Object.entries(values)) this.setExport(key, value)
    }, { context })
  })
  await module.evaluate()
  const state = module.namespace
  const oldLoad = state.loadCurrentUser()
  state.setCurrentUser(account(2, false))
  finishOldRequest({ data: account(1, true) })
  await oldLoad
  assert.equal(state.currentUser.value.id, 2)
  assert.equal(state.currentUser.value.emailVerified, false)
})

test('account status requests bypass a cached verified account', async () => {
  const context = vm.createContext({
    fetch: async (_url, options) => ({
      ok: true,
      json: async () => ({ data: account(options.cache === 'no-store' ? 2 : 1, options.cache !== 'no-store') }),
    }),
  })
  const source = await readFile(new URL('../src/lib/api.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(() => new vm.SyntheticModule(['default'], function () {
    this.setExport('default', { global: { locale: { value: 'bs' }, t: (key) => key } })
  }, { context }))
  await module.evaluate()
  for (const endpoint of ['/auth/me', '/auth/session']) {
    const response = await module.namespace.apiGet(endpoint)
    assert.equal(response.data.id, 2)
    assert.equal(response.data.emailVerified, false)
  }
})
