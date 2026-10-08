import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

async function metrics(id = 'G-TEST123') {
  const scripts = []
  const cookies = []
  const window = { location: { origin: 'https://wave.ba', pathname: '/kampanje', hostname: 'wave.ba' } }
  const document = { title: 'Wave', createElement: () => ({ dataset: {} }), head: { append: (node) => scripts.push(node) }, get cookie() { return '_ga=123; _ga_TEST=456; wave_session=789' }, set cookie(value) { cookies.push(value) } }
  const context = vm.createContext({ window, document, URL })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/privacyMetrics.js', import.meta.url), 'utf8'), { context, initializeImportMeta: (meta) => { meta.env = { VITE_GA_MEASUREMENT_ID: id } } })
  await module.link(() => { throw new Error('Unexpected dependency') })
  await module.evaluate()
  return { api: module.namespace, window, scripts, cookies }
}

test('analytics loads only after consent and queues Google-compatible commands once', async () => {
  const { api, window, scripts } = await metrics()
  api.trackPageView('/kreatori')
  assert.equal(scripts.length, 0)
  api.setAnalyticsConsent(true)
  assert.equal(scripts.length, 1)
  assert.equal(scripts[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-TEST123')
  const commands = window.dataLayer
  assert.ok(commands.every((command) => Object.prototype.toString.call(command) === '[object Arguments]'))
  assert.equal(commands[0][0], 'consent')
  assert.equal(commands[0][2].analytics_storage, 'denied')
  assert.equal(commands[3][2].analytics_storage, 'granted')
  assert.equal(commands[4][1], 'page_view')
  api.trackPageView('/kreatori?email=private@example.com#secret')
  assert.equal(commands.at(-1)[2].page_location, 'https://wave.ba/kreatori')
  api.setAnalyticsConsent(true)
  assert.equal(scripts.length, 1)
})

test('withdrawing consent stops events and deletes only analytics cookies', async () => {
  const { api, window, cookies } = await metrics()
  api.setAnalyticsConsent(true)
  api.setAnalyticsConsent(false)
  const count = window.dataLayer.length
  api.trackPageView('/racun')
  assert.equal(window.dataLayer.length, count)
  assert.equal(window.dataLayer.at(-1)[2].analytics_storage, 'denied')
  assert.ok(cookies.some((cookie) => cookie.startsWith('_ga=;')))
  assert.ok(cookies.every((cookie) => !cookie.includes('wave_session')))
  const disabled = await metrics('')
  disabled.api.setAnalyticsConsent(true)
  assert.equal(disabled.scripts.length, 0)
})
