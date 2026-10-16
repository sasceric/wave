import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { setupView } from './setupView.js'

test('proposal ranges have real translations in all six languages without exposing a precise count', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    const i18n = createI18n({ legacy: false, locale, messages: { [locale]: catalog } })
    const props = reactive({ range: null })
    const { state } = await setupView('../src/components/campaigns/CampaignApplicationRange.vue', {
      'vue-i18n': { useI18n: () => i18n.global },
    }, props)
    assert.equal(state.label.value, '')
    for (const range of ['fewerThan5', 'upTo10', '10to20', '20to50', '50plus']) {
      props.range = range
      assert.equal(state.label.value, catalog.campaignApplications.ranges[range])
    }
    for (const unknown of ['123', 'not-loaded', undefined]) {
      props.range = unknown
      assert.equal(state.label.value, '')
    }
    i18n.dispose()
  }
})

test('a successful application refreshes the range from its response without another campaign request', async () => {
  const requests = []
  const { state } = await setupView('../src/views/CampaignDetailView.vue', {
    'vue-router': { useRoute: () => ({ params: { slug: 'spring' }, meta: { routeName: 'campaign-detail' } }) },
    '../composables/useCredits': { refreshCredits: async () => {} },
    '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: () => {} },
    '../lib/api': {
      apiGet: () => { throw new Error('No additional GET is needed') },
      apiRequest: async (path) => { requests.push(path); return { data: { campaign: { slug: 'spring', applicationRange: 'upTo10' } } } },
      formatDate: value => value, formatMoney: value => value,
    },
  })
  state.campaign.value = { slug: 'spring', applicationRange: 'fewerThan5', company: { name: 'Brand', slug: 'brand' } }
  state.applicationMessage.value = 'My proposal for this campaign.'
  await state.applyToCampaign()
  assert.deepEqual(requests, ['/campaigns/spring/applications'])
  assert.equal(state.campaign.value.applicationRange, 'upTo10')
  assert.equal(state.applicationSent.value, true)
  assert.equal(state.applyError.value, '')
})
