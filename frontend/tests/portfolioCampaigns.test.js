import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { emptyWork, PRESENTATION_PLATFORMS, workImage } from '../src/lib/creatorPresentation.js'
import { htmlToPlainText } from '../src/lib/profileDraft.js'
import { setupView } from './setupView.js'

async function studio(apiGet, overrides = {}) {
  const props = vue.reactive({ tab: 'portfolio', editing: false, profile: { id: 1, portfolio: [{ ...emptyWork(), source: 'wave', contribution: 'My own contribution.' }], socialProfiles: [], packages: [] } })
  Object.assign(props, overrides)
  const view = await setupView('../src/components/account/CreatorStudioPanel.vue', {
    vue: { ...vue, onBeforeUnmount: () => {}, useModel: (props, name) => vue.toRef(props, name) },
    'vue-i18n': { useI18n: () => ({ locale: vue.ref('bs'), t: key => key, te: () => false }) },
    '../../data/countries.json': { default: ['BA'] },
    '../../lib/api': { apiGet, formatMoney: String },
    '../../lib/creatorPresentation': { emptyWork, PRESENTATION_PLATFORMS, workImage },
    '../../lib/profileDraft': { htmlToPlainText },
  }, props)
  view.state.selectedWork.value = 0
  return { ...view, props }
}

test('choosing completed work fills campaign details and replaces previous campaign data', async () => {
  const { state, props } = await studio(async () => ({ data: [
    { id: 10, title: 'Brand campaign', summary: 'Short brief', description: '<p>Film a <b>product</b> video.</p>', company: 'Brand', companyType: 'brand', completedAt: '2026-10-26', finishedAt: null, channels: ['Instagram'] },
    { id: 20, title: 'Agency campaign', summary: 'New summary', description: '<p>New brief.</p>', company: 'Agency', companyType: 'agency', completedAt: '2026-10-25', channels: ['Instagram', 'TikTok'] },
  ], meta: { nextCursor: null } }))
  await state.loadCampaigns()
  state.pickCampaign(10)
  const work = props.profile.portfolio[0]
  assert.equal(work.title, 'Brand campaign')
  assert.equal(work.summary, 'Short brief')
  assert.equal(work.brief, 'Film a product video.')
  assert.equal(work.projectDate, '2026-10-26')
  assert.equal(work.client, 'Brand')
  assert.equal(work.agency, '')
  assert.equal(work.platform, 'Instagram')
  state.pickCampaign(20)
  assert.equal(work.title, 'Agency campaign')
  assert.equal(work.summary, 'New summary')
  assert.equal(work.brief, 'New brief.')
  assert.equal(work.client, '')
  assert.equal(work.agency, 'Agency')
  assert.equal(work.projectDate, '2026-10-25')
  assert.equal(work.platform, 'All')
  assert.equal(work.contribution, 'My own contribution.')
})

test('campaign options paginate and failed refreshes expose an error for retry', async () => {
  const paths = []
  let fail = false
  const { state } = await studio(async path => {
    paths.push(path)
    if (fail) throw new Error('Connection failed')
    return path.includes('before=')
      ? { data: [{ id: 1, title: 'Older', company: 'Brand' }], meta: { nextCursor: null } }
      : { data: [{ id: 21, title: 'Newer', company: 'Brand' }], meta: { nextCursor: 21 } }
  })
  await state.loadCampaigns()
  await state.loadCampaigns(true)
  assert.deepEqual(paths, ['/me/portfolio-campaigns', '/me/portfolio-campaigns?before=21'])
  assert.equal(state.campaignOptions.value.length, 2)
  fail = true
  await state.loadCampaigns()
  assert.equal(state.loadError.value, 'Connection failed')
  assert.equal(state.loading.value, false)
})


test('an organizing agency loads campaign choices without a provider studio', async () => {
  const paths = []
  const { state } = await studio(async path => {
    paths.push(path)
    return { data: [{ id: 42, title: 'Agency organized campaign', company: 'Agency', companyType: 'agency' }], meta: { nextCursor: null } }
  }, { editing: true, profile: { id: 2, companyType: 'agency', providerId: null, portfolio: [], socialProfiles: [], packages: [] } })
  await vue.nextTick()
  assert.deepEqual(paths, ['/me/portfolio-campaigns'])
  assert.equal(state.campaignOptions.value[0].value, 42)
  assert.equal(state.loading.value, false)
})
