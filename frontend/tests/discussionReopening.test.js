import assert from 'node:assert/strict'
import { test } from 'node:test'
import { reactive, ref, nextTick } from 'vue'
import { setupView } from './setupView.js'

test('reopening requests preserve failed drafts and provider replies carry the current consent version', async () => {
  const calls = []
  let fail = true
  const props = reactive({ conversationId: 8, reopening: { status: null, version: 0 }, provider: false, canRequest: true })
  const { state, emitted } = await setupView('../src/components/account/DiscussionReopeningAction.vue', {
    '../../lib/api': { apiRequest: async (url, options) => { calls.push({ url, ...options }); if (fail) throw new Error('Try again'); return { data: { status: 'pending', version: 1 } } } },
  }, props)
  state.request()
  state.message.value = 'Let us discuss a new collaboration together.'
  await state.submit('request')
  assert.equal(state.open.value, true)
  assert.equal(state.error.value, 'Try again')
  assert.equal(state.message.value, 'Let us discuss a new collaboration together.')
  fail = false
  await state.submit('request')
  assert.equal(calls[1].url, '/me/conversations/8/reopen')
  assert.equal(calls[1].body.action, 'request')
  assert.equal(emitted.length, 1)
  assert.equal(state.open.value, false)
  props.reopening = { status: 'pending', version: 3 }
  props.provider = true
  await state.submit('accept')
  assert.equal(calls[2].body.version, 3)
  assert.equal(calls[2].body.action, 'accept')
  assert.equal('message' in calls[2].body, false)
})

test('a delayed reopening result cannot affect a different thread or account', async () => {
  const currentUser = ref({ id: 10 })
  const props = reactive({ conversationId: 8, reopening: { status: 'pending', version: 2 }, provider: true })
  let resolve
  const { state, emitted } = await setupView('../src/components/account/DiscussionReopeningAction.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/api': { apiRequest: () => new Promise(done => { resolve = done }) },
  }, props)
  const pending = state.submit('accept')
  props.conversationId = 9
  currentUser.value = { id: 12 }
  await nextTick()
  resolve({ data: { status: 'accepted' } })
  await pending
  assert.equal(emitted.length, 0)
  assert.equal(state.open.value, false)
  assert.equal(state.busy.value, false)
})
