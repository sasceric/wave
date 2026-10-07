import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

const settings = { paid: false, unitPriceMinor: 20, applicationCost: 10, campaignCost: 30, welcomeGrant: 50, version: 1, packs: [{ amount: 50, priceMinor: 1000 }] }
const account = { balance: 0, unlimited: true, settings, history: [], page: 1, total: 0 }

test('paid activation asks for confirmation and sends integer money values only when confirmed', async () => {
  const requests = []
  const { state } = await setupView('../src/views/AdminCreditSettingsView.vue', {
    '../lib/api': {
      formatDate: value => value,
      apiGet: async path => ({ data: path.includes('vouchers') ? { rows: [], page: 1, total: 0 } : settings }),
      apiRequest: async (path, options) => { requests.push([path, options]); return { data: { ...options.body, version: 2 } } },
    },
    '../composables/useCredits': { refreshCredits: async () => {} },
  })
  await state.load()
  state.switchValue.value = 1
  state.price.value = 0.29
  state.submit()
  assert.equal(state.confirming.value, true)
  assert.equal(requests.length, 0)
  await state.save()
  assert.equal(requests[0][1].body.unitPriceMinor, 29)
  assert.equal(requests[0][1].body.paid, true)
  assert.equal(state.confirming.value, false)
})

test('a successful redemption clears the code, refreshes balance and opens the role-specific success action', async () => {
  const updated = []
  const { state, currentUser } = await setupView('../src/views/CreditsView.vue', {
    '../lib/api': { formatDate: value => value, apiGet: async () => ({ data: account }), apiRequest: async () => ({ data: { ...account, balance: 50, added: 50 } }) },
    '../composables/useCredits': { setCreditAccount: value => updated.push(value) },
  })
  state.code.value = 'ABCD1234'
  await state.redeem()
  assert.equal(state.code.value, '')
  assert.equal(state.success.value, true)
  assert.equal(state.added.value, 50)
  assert.equal(updated[0].balance, 50)
  assert.equal(state.nextRoute.value.name, 'campaigns')
  currentUser.value = { id: 1, accountType: 'company' }
  assert.equal(state.nextRoute.value.name, 'account-campaign-create')
})

test('an invalid code stays available for correction and never opens success', async () => {
  const { state } = await setupView('../src/views/CreditsView.vue', {
    '../lib/api': { formatDate: value => value, apiGet: async () => ({ data: account }), apiRequest: async () => { throw new Error('Invalid code') } },
    '../composables/useCredits': { setCreditAccount: () => {} },
  })
  state.code.value = 'INVALID1'
  await state.redeem()
  assert.equal(state.code.value, 'INVALID1')
  assert.equal(state.redeemError.value, 'Invalid code')
  assert.equal(state.success.value, false)
  assert.equal(state.busy.value, false)
})

test('paid action notice uses the configurable cost and detects insufficient funds', async () => {
  const creditAccount = ref({ ...account, unlimited: false, balance: 25 })
  const { state } = await setupView('../src/components/account/CreditActionNotice.vue', {
    '../../composables/useCredits': { creditAccount, refreshCredits: async () => {} },
  }, { action: 'campaign' })
  assert.equal(state.cost.value, 30)
  assert.equal(state.insufficient.value, true)
  creditAccount.value.unlimited = true
  assert.equal(state.insufficient.value, false)
})

test('history filtering resets pagination and requests the full filtered history', async () => {
  const requested = []
  const { state } = await setupView('../src/views/CreditsView.vue', {
    '../lib/api': { formatDate: value => value, apiRequest: async () => ({}), apiGet: async path => { requested.push(path); return { data: account } } },
    '../composables/useCredits': { setCreditAccount: () => {} },
  })
  state.page.value = 3
  state.filterHistory('spent')
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.page.value, 1)
  assert.equal(requested[0], '/me/credits?page=1&type=spent')
})

test('credit copy and both routes are present in every supported language', async () => {
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url)))
  const english = JSON.parse(await readFile(new URL('../src/locales/en.json', import.meta.url)))
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    assert.deepEqual(Object.keys(catalog.credits).sort(), Object.keys(english.credits).sort())
    assert.ok(routes[locale]['account-credits'])
    assert.ok(routes[locale]['admin-credit-settings'])
  }
})
