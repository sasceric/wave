import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

async function listing() {
  const props = reactive({ campaigns: Array.from({ length: 23 }, (_, index) => ({
    id: index + 1, slug: `campaign-${index + 1}`, title: `Campaign ${index + 1}`,
    summary: index === 22 ? 'A special brief' : 'Campaign summary',
    status: ['open', 'closed', 'finished'][index % 3],
    channels: ['Instagram'], city: 'Zenica', category: 'Travel',
  })) })
  const view = await setupView('../src/components/account/CompanyCampaignList.vue', {}, props)
  return { ...view, props }
}

test('campaign listing uses ten-item pages and keeps the final partial page', async () => {
  const { state } = await listing()
  assert.equal(state.pageCampaigns.value.length, 10)
  state.page.value = 2
  await nextTick()
  assert.deepEqual(Array.from(state.pageCampaigns.value, campaign => campaign.id), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
  state.page.value = 3
  await nextTick()
  assert.deepEqual(Array.from(state.pageCampaigns.value, campaign => campaign.id), [21, 22, 23])
})

test('status counts cover all campaigns and searching is combined with the selected status', async () => {
  const { state } = await listing()
  assert.deepEqual(Array.from(state.filters.value, filter => filter.count), [23, 8, 8, 7])
  state.page.value = 3
  state.query.value = '  SPECIAL BRIEF  '
  await nextTick()
  assert.equal(state.page.value, 1)
  assert.deepEqual(Array.from(state.pageCampaigns.value, campaign => campaign.id), [23])
  state.statusFilter.value = 'open'
  await nextTick()
  assert.equal(state.pageCampaigns.value.length, 0)
  state.statusFilter.value = 'closed'
  await nextTick()
  assert.deepEqual(Array.from(state.pageCampaigns.value, campaign => campaign.id), [23])
  state.query.value = ''
  await nextTick()
  assert.equal(state.pageCampaigns.value.length, 8)
})

test('list refreshes clamp pagination and campaign links target account details', async () => {
  const { state, props } = await listing()
  state.page.value = 3
  props.campaigns = props.campaigns.slice(0, 11)
  await nextTick()
  assert.equal(state.page.value, 2)
  assert.deepEqual(Array.from(state.pageCampaigns.value, campaign => campaign.id), [11])
  assert.equal(state.detailRoute(props.campaigns[0]).name, 'account-campaign-detail')
  assert.equal(state.detailRoute(props.campaigns[0]).params.slug, 'campaign-1')
})

test('campaign listing copy is translated in every locale', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    for (const key of ['backToCampaigns', 'companyCampaignsIntro', 'hiredCreators', 'searchCompanyCampaigns', 'campaignDeadlineInDays', 'campaignDeadlinePassed']) {
      assert.ok(catalog.account[key], `${locale}: ${key}`)
    }
  }
})
