import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { setupView } from './setupView.js'

const legalDocuments = JSON.parse(readFileSync(new URL('../../config/legal_documents.json', import.meta.url), 'utf8'))

async function banner(entries = {}, throws = false) {
  const values = new Map(Object.entries(entries)), analytics = [], marketing = []
  const localStorage = { getItem: key => { if (throws) throw new Error('Storage unavailable'); return values.get(key) || null }, setItem: (key, value) => { if (throws) throw new Error('Storage unavailable'); values.set(key, value) } }
  const view = await setupView('../src/components/shared/CookieConsentBanner.vue', {
    '../../lib/privacyMetrics': { setAnalyticsConsent: value => analytics.push(value) },
    '../../lib/metaTracking': { setMarketingConsent: value => marketing.push(value) },
    '../../../../config/legal_documents.json': { default: legalDocuments },
  }, {}, { localStorage })
  return { ...view, values, analytics, marketing }
}

test('choices saved under an older policy are not reused: the banner asks again with everything off', async () => {
  const { state, analytics, marketing } = await banner({
    'wave-cookie-consent-v2': JSON.stringify({ version: 2, analytics: true }),
    'wave-cookie-consent-v3': JSON.stringify({ version: 3, analytics: true, marketing: true, updatedAt: new Date().toISOString() }),
  })
  state.restorePreferences()
  assert.equal(state.visible.value, true)
  assert.equal(state.analyticsAllowed.value, false)
  assert.equal(state.marketingAllowed.value, false)
  assert.deepEqual(analytics, [false]); assert.deepEqual(marketing, [false])
})

test('marketing and analytics choices are independent, persisted and synchronized across tabs', async () => {
  const { state, values, analytics, marketing } = await banner()
  state.savePreferences(false, true)
  assert.equal(analytics.at(-1), false); assert.equal(marketing.at(-1), true)
  assert.equal(JSON.parse(values.get('wave-cookie-consent-v3')).marketing, true)
  const saved = JSON.parse(values.get('wave-cookie-consent-v3'))
  values.set('wave-cookie-consent-v3', JSON.stringify({ ...saved, analytics: true, marketing: false }))
  state.syncPreferences({ key: 'wave-cookie-consent-v3' })
  assert.equal(analytics.at(-1), true); assert.equal(marketing.at(-1), false)
  state.savePreferences(false, false)
  assert.equal(state.visible.value, false)
})

test('corrupt, missing and inaccessible storage fail closed and cannot break the interface', async () => {
  for (const config of [{}, { 'wave-cookie-consent-v3': '{broken' }]) {
    const { state, marketing } = await banner(config)
    state.restorePreferences()
    assert.equal(state.visible.value, true); assert.equal(marketing.at(-1), false)
  }
  const { state, marketing } = await banner({}, true)
  assert.doesNotThrow(() => state.restorePreferences())
  assert.equal(marketing.at(-1), false)
  assert.doesNotThrow(() => state.savePreferences(false, false))
})

test('opening cookie settings restores saved choices and expands the existing shared switches', async () => {
  const { state, values } = await banner()
  state.restorePreferences()
  assert.equal(state.preferencesOpen.value, false)
  assert.equal(state.analyticsSwitch.value, 0)
  assert.equal(state.marketingSwitch.value, 0)
  state.analyticsSwitch.value = 1
  assert.equal(state.analyticsAllowed.value, true)
  assert.equal(values.has('wave-cookie-consent-v3'), false)
  state.savePreferences(true, false)
  state.openPreferences()
  assert.equal(state.visible.value, true)
  assert.equal(state.preferencesOpen.value, true)
  assert.equal(state.analyticsSwitch.value, 1)
  assert.equal(state.marketingSwitch.value, 0)
})
