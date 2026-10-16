import assert from 'node:assert/strict'
import { test } from 'node:test'
import { computed, nextTick, ref } from 'vue'
import { setupView } from './setupView.js'

const tick = () => new Promise(resolve => setImmediate(resolve))

test('setup checklist follows required fields and keeps portfolio optional', async () => {
  const user = { id: 1, accountType: 'creator', emailVerified: true, profile: { displayName: 'Amina', categories: [] }, phone: '', city: '', countryCode: '' }
  const { state } = await setupView('../src/components/account/AccountActivationPanel.vue', {}, { user })
  assert.equal(state.complete.value, 1)
  user.phone = '+38761123456'; user.city = 'Sarajevo'; user.countryCode = 'BA'; user.profile.categories.push('Travel')
  // Recreate with the server's updated account resource, as the parent does after saving.
  const updated = await setupView('../src/components/account/AccountActivationPanel.vue', {}, { user })
  assert.equal(updated.state.complete.value, 3)
  assert.equal(updated.state.optional.value.every(step => !step.done), true)
  assert.equal(updated.state.steps.value.every(step => step.done), true)
})

test('campaign alert preferences discard old account responses and never imply a failed save succeeded', async () => {
  const currentUser = ref({ id: 1, accountType: 'creator' })
  const pending = []
  const { state } = await setupView('../src/components/account/CampaignAlertSettings.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../composables/useMarketplaceCatalog': { useMarketplaceCatalog: () => ({ categories: ref([]), error: ref('') }) },
    '../../lib/api': {
      apiGet: () => new Promise(resolve => pending.push(resolve)),
      apiRequest: async () => { throw new Error('Save failed') },
    },
  })
  currentUser.value = { id: 2, accountType: 'creator' }; await nextTick()
  pending[0]({ userId: 1, data: { enabled: true, countries: ['BA'], categories: ['Travel'], frequency: 'daily' } }); await tick()
  assert.equal(state.loaded.value, false)
  pending[1]({ userId: 2, data: { enabled: false, countries: [], categories: [], frequency: 'weekly' } }); await tick()
  assert.equal(state.loaded.value, true)
  state.form.value.enabled = true
  assert.equal(state.stored.value.enabled, false)
  await state.save()
  assert.equal(state.stored.value.enabled, false)
  assert.equal(state.saved.value, false)
  assert.equal(state.error.value, 'Save failed')
})

test('visibility cannot be enabled before all eligibility rules pass', async () => {
  const currentUser = ref({ id: 1, approved: true, emailVerified: false, profileComplete: true })
  const calls = []
  const { state } = await setupView('../src/components/account/AccountVisibilityCard.vue', {
    '../../composables/useCurrentUser': { currentUser, setCurrentUser: user => { currentUser.value = user } },
    '../../lib/api': { apiRequest: async (path, options) => { calls.push({ path, body: options.body }); return { data: { ...currentUser.value, hide_my_account: 0 } } } },
  })
  await state.visibility(1)
  assert.equal(calls.length, 0)
  currentUser.value = { ...currentUser.value, emailVerified: true }
  await state.visibility(1)
  assert.equal(calls[0].body.hide_my_account, 0)
  assert.equal(currentUser.value.hide_my_account, 0)
})


test('pending accounts refresh verification and approval on inbox resume events and clean up without polling', async () => {
  let refreshed = 0
  let mount, unmount
  const { state, windowTarget } = await setupView('../src/components/account/AccountActivationPanel.vue', {
    vue: { computed, ref, onMounted: callback => { mount = callback }, onBeforeUnmount: callback => { unmount = callback } },
    '../../composables/useCurrentUser': { loadCurrentUser: async () => { refreshed++ } },
  }, { user: { id: 1, accountType: 'creator', profile: {} } })
  mount()
  windowTarget.dispatchEvent(new Event('wave:inbox-refresh'))
  await tick()
  assert.equal(refreshed, 1)
  assert.equal(state.refreshing.value, false)
  unmount()
  windowTarget.dispatchEvent(new Event('wave:inbox-refresh'))
  await tick()
  assert.equal(refreshed, 1)
})
