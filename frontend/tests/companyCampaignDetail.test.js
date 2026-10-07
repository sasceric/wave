import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

async function detail(count = 23) {
  const props = reactive({
    campaign: { slug: 'campaign-one', category: 'Travel', categories: [], categoryLabels: [] },
    applications: Array.from({ length: count }, (_, index) => ({
      id: index + 1, conversationId: index === 0 ? 42 : null,
      status: index === 0 ? 'accepted' : 'pending',
      creator: { displayName: `Creator ${index + 1}`, category: 'Travel', city: index === 21 ? 'Zenica' : 'Sarajevo' },
      message: index === 22 ? 'Special application message' : '',
    })),
  })
  const view = await setupView('../src/components/account/CompanyCampaignDetail.vue', {}, props)
  return { ...view, props }
}

test('campaign applications paginate in complete, non-overlapping batches of ten', async () => {
  const { state } = await detail()
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
  state.page.value = 2
  await nextTick()
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
  state.page.value = 3
  await nextTick()
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [21, 22, 23])
})

test('search covers applicants on every page and resets pagination', async () => {
  const { state } = await detail()
  state.page.value = 3
  state.query.value = '  ZENICA  '
  await nextTick()
  assert.equal(state.page.value, 1)
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [22])
  state.query.value = 'special application'
  await nextTick()
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [23])
  state.query.value = 'no match'
  await nextTick()
  assert.equal(state.pageApplications.value.length, 0)
  state.query.value = ''
  await nextTick()
  assert.equal(state.filteredApplications.value.length, 23)
  assert.equal(state.pageApplications.value.length, 10)
})

test('refreshing a shorter list clamps the page and changing campaigns clears search', async () => {
  const { state, props } = await detail()
  state.page.value = 3
  props.applications = props.applications.slice(0, 12)
  await nextTick()
  assert.equal(state.page.value, 2)
  assert.deepEqual(Array.from(state.pageApplications.value, item => item.id), [11, 12])
  state.query.value = 'Creator 12'
  await nextTick()
  props.campaign = { slug: 'campaign-two', category: 'Food', categoryLabels: ['Food and drink'] }
  await nextTick()
  assert.equal(state.query.value, '')
  assert.equal(state.page.value, 1)
  assert.equal(state.pageApplications.value.length, 10)
  assert.deepEqual(Array.from(state.campaignAreas.value), ['Food and drink'])
})

test('chat links use the existing conversation and legacy campaign areas remain visible', async () => {
  const { state, props } = await detail()
  assert.equal(state.chatRoute(props.applications[0]).name, 'messages')
  assert.equal(state.chatRoute(props.applications[0]).query.conversation, 42)
  assert.deepEqual(Array.from(state.campaignAreas.value), ['Travel'])
})

test('campaign applicants copy is present in all six locales', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    assert.ok(catalog.account.campaignApplicantsIntro)
    assert.ok(catalog.account.applicationDate)
  }
})
