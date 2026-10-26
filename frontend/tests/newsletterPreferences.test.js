import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

test('standalone newsletter links show only newsletter and confirmation requires an explicit write', async () => {
  const calls = []
  let mounted
  const { state } = await setupView('../src/views/AccountEmailPreferencesView.vue', {
    vue: { ...vue, onMounted: callback => { mounted = callback }, onBeforeUnmount: () => {} },
    'vue-router': {
      useRoute: () => ({ query: { n: '12', s: 'signed-token', confirm: '1' } }),
      useRouter: () => ({ replace: async () => {} }),
    },
    '../lib/api': { apiRequest: async (path, options) => {
      calls.push({ path, options })
      return { data: { email: 'am•••@example.test', profileTips: null, newsletter: options?.body?.newsletter === true, campaignAlerts: null } }
    } },
  })
  await mounted()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].options, undefined)
  assert.match(calls[0].path, /n=12&s=signed-token/)
  assert.deepEqual([...state.fields.value], ['newsletter'])
  assert.equal(state.confirmation.value, true)
  await state.save({ newsletter: true })
  assert.equal(calls[1].options.method, 'PUT')
  assert.equal(calls[1].options.body.newsletter, true)
  assert.equal(state.confirmation.value, false)
})
