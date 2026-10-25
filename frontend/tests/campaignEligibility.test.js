import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { setupView } from './setupView.js'

const source = await readFile(new URL('../src/views/CampaignDetailView.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)
const campaign = {
  id: 4, slug: 'spring', title: 'Spring campaign', status: 'open',
  company: { name: 'Brand', slug: 'brand', ownerId: 20 },
  closesAt: '2099-12-01', channels: [],
}

async function campaignView(user) {
  const calls = []
  const { state } = await setupView('../src/views/CampaignDetailView.vue', {
    'vue-router': { useRoute: () => ({ params: { slug: 'spring' }, path: '/kampanje/spring', query: {}, meta: {} }) },
    '../lib/seo': { getSeoOrigin: () => 'https://wave.ba', updateSeo: () => {} },
    '../routePaths': { localizedPath: () => '/kompanije/brand' },
    '../composables/useCredits': { refreshCredits: async () => {} },
    '../lib/api': {
      apiGet: async path => {
        calls.push({ method: 'GET', path })
        if (path === '/auth/session') return { data: user }
        if (path === '/campaigns/spring') return { data: campaign }
        if (path === '/me/applications' || path === '/me/invitations') return { data: [] }
        throw new Error(`Unexpected request: ${path}`)
      },
      apiRequest: async (path, options) => {
        calls.push({ method: options.method, path })
        return { data: {} }
      },
      formatDate: value => value,
    },
  })
  await state.loadCampaign()
  const app = vue.createSSRApp({ setup: () => ({ ...state }), render })
  const stub = { inheritAttrs: false, setup: (_, { slots }) => () => vue.h('span', null, slots.default?.()) }
  const components = new Set([...descriptor.template.content.matchAll(/<([A-Z][A-Za-z]+)/g)].map(match => match[1]))
  for (const name of components) {
    if (name !== 'StatusMessage') app.component(name, stub)
  }
  app.component('StatusMessage', { setup: (_, { slots }) => () => vue.h('p', { role: 'status' }, slots.default?.()) })
  app.directive('form-validation', {})
  return { state, calls, html: await renderToString(app) }
}

test('brand visitors see an eligibility notice without an email fallback or application action', async () => {
  const { state, calls, html } = await campaignView({ id: 1, accountType: 'company', canOfferServices: false, approved: true, emailVerified: true })
  assert.match(html, /role="status">campaignDetail.applicantsOnlyNotice/)
  assert.doesNotMatch(html, /mailto:|campaignDetail.askAbout|>account.apply\s/)
  assert.equal(state.showApplyBar.value, false)
  assert.equal(calls.some(call => call.path === '/me/applications' || call.path === '/me/invitations'), false)
  await state.applyToCampaign()
  assert.equal(calls.some(call => call.method === 'POST'), false)
})

test('creators and agencies keep the campaign application action and can submit', async () => {
  for (const account of [{ accountType: 'creator' }, { accountType: 'company', canOfferServices: true, profile: { companyType: 'agency' } }]) {
    const { state, calls, html } = await campaignView({ id: 1, approved: true, emailVerified: true, ...account })
    assert.match(html, />account.apply\s/)
    assert.doesNotMatch(html, /campaignDetail.applicantsOnlyNotice|mailto:/)
    assert.equal(state.showApplyBar.value, true)
    state.applicationMessage.value = 'My idea for this campaign and its content.'
    await state.applyToCampaign()
    assert.equal(calls.filter(call => call.method === 'POST' && call.path === '/campaigns/spring/applications').length, 1)
  }
})

test('owners cannot apply to their own campaigns, including agency owners', async () => {
  for (const canOfferServices of [false, true]) {
    const { state, calls, html } = await campaignView({ id: 20, accountType: 'company', canOfferServices, approved: true, emailVerified: true })
    assert.match(html, /role="status">campaignDetail.ownCampaignNotice/)
    assert.doesNotMatch(html, /mailto:|>account.apply\s/)
    assert.equal(state.showApplyBar.value, false)
    await state.applyToCampaign()
    assert.equal(calls.some(call => call.method === 'POST'), false)
  }
})

test('guests retain the registration action and are not treated as owners without an account ID', async () => {
  const { state, html } = await campaignView(null)
  state.campaign.value = { ...campaign, company: { name: 'Brand', slug: 'brand' } }
  assert.equal(state.ownCampaign.value, false)
  assert.match(html, /registrationPrompts.campaignCreator.action/)
  assert.doesNotMatch(html, /campaignDetail.applicantsOnlyNotice|mailto:/)
})

test('campaign eligibility explanations exist in every supported locale', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr', 'de']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    for (const key of ['applicantsOnlyNotice', 'ownCampaignNotice']) {
      assert.equal(typeof catalog.campaignDetail[key], 'string', `${locale}: ${key}`)
      assert.ok(catalog.campaignDetail[key].trim())
    }
  }
})
