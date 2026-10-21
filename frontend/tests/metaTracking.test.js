import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import { randomUUID } from 'node:crypto'

async function tracking({ enabled = 'true', id = '123456789012345', href = 'https://wave.ba/kreatori', hosts } = {}) {
  const scripts = [], cookies = []
  const window = { location: new URL(href), crypto: { randomUUID } }
  const document = { createElement: () => ({ dataset: {} }), head: { append: node => scripts.push(node) }, get cookie() { return '_fbp=123; _fbc=456; wave_session=789; _ga=abc' }, set cookie(value) { cookies.push(value) } }
  const context = vm.createContext({ window, document, URL })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/metaTracking.js', import.meta.url), 'utf8'), { context, initializeImportMeta: meta => { meta.env = { VITE_META_PIXEL_ENABLED: enabled, VITE_META_PIXEL_ID: id, VITE_META_PIXEL_HOSTS: hosts } } })
  await module.link(() => { throw new Error('Unexpected dependency') })
  await module.evaluate()
  return { api: module.namespace, scripts, cookies, window, commands: () => window.fbq?.queue || [] }
}
const creator = { id: 8, accountType: 'creator', email: 'private@example.test', phone: 'secret', profile: { displayName: 'Private Name' } }

test('Meta stays completely unloaded without marketing consent, a valid ID, explicit enablement and a production host', async () => {
  for (const config of [{ enabled: 'false' }, { id: '' }, { id: 'invalid' }, { href: 'http://127.0.0.1:5173/kreatori' }]) {
    const { api, scripts, window } = await tracking(config)
    api.trackMetaPageView('/kreatori'); api.setMarketingConsent(true); api.trackRegistration(creator)
    assert.equal(scripts.length, 0); assert.equal(window.fbq, undefined)
  }
  const { api, scripts } = await tracking()
  api.trackMetaPageView('/kreatori'); api.trackRegistration(creator)
  assert.equal(scripts.length, 0)
})

test('consented public page views use one loader, disable automatic events, target only this Pixel and deduplicate SEO refreshes', async () => {
  const { api, scripts, commands, window } = await tracking()
  api.setMarketingConsent(true)
  assert.equal(scripts.length, 0, 'Wait for resolved public metadata.')
  api.trackMetaPageView('/kreatori')
  assert.equal(scripts.length, 1)
  assert.equal(scripts[0].src, 'https://connect.facebook.net/en_US/fbevents.js')
  assert.deepEqual(Array.from(commands()[1]), ['set', 'autoConfig', false, '123456789012345'])
  api.trackMetaPageView('/kreatori')
  assert.equal(commands().filter(row => row[2] === 'PageView').length, 1)
  window.location = new URL('https://wave.ba/kompanije')
  api.trackMetaPageView('/kompanije')
  assert.equal(scripts.length, 1)
  assert.equal(commands().filter(row => row[2] === 'PageView').length, 2)
})

test('the Meta loader uses the standard Arguments queue and SDK receiver contract', async () => {
  const { api, commands, window } = await tracking()
  api.trackMetaPageView('/kreatori')
  api.setMarketingConsent(true)
  assert.ok(commands().length > 0)
  assert.ok(commands().every(command => Object.prototype.toString.call(command) === '[object Arguments]'))

  const calls = []
  window.fbq.callMethod = function (...args) { calls.push({ receiver: this, args }) }
  window.location = new URL('https://wave.ba/kompanije')
  api.trackMetaPageView('/kompanije')
  assert.equal(calls.length, 1)
  assert.equal(calls[0].receiver, window.fbq)
  assert.deepEqual(calls[0].args.slice(0, 3), ['trackSingle', '123456789012345', 'PageView'])
})

test('private/search pages and unsafe URLs do not initialize or send queued public views', async () => {
  for (const href of ['https://wave.ba/racun?token=secret', 'https://wave.ba/?email=private@example.test', 'https://wave.ba/#secret', 'https://wave.ba/?return=/poruke', 'https://wave.ba/?utm_campaign=private%40example.test']) {
    const { api, scripts } = await tracking({ href })
    api.trackMetaPageView(new URL(href).pathname); api.setMarketingConsent(true); api.trackRegistration(creator)
    assert.equal(scripts.length, 0)
  }
  const { api, scripts, window } = await tracking()
  api.trackMetaPageView('/kreatori')
  window.location = new URL('https://wave.ba/poruke')
  api.setMarketingConsent(true)
  assert.equal(scripts.length, 0, 'Stale public metadata cannot initialize on a private location.')
  api.trackMetaPageView('/poruke', { allowed: false })
  assert.equal(scripts.length, 0)
})

test('creator, company and agency registrations send one allowlisted event without personal data or financial values', async () => {
  for (const user of [creator, { ...creator, accountType: 'company', profile: { companyType: 'brand' } }, { ...creator, accountType: 'company', profile: { companyType: 'agency' } }]) {
    const { api, commands } = await tracking({ href: 'https://wave.ba/racun?mode=register&accountType='+user.accountType })
    api.setMarketingConsent(true)
    api.trackRegistration(user, 'social'); api.trackRegistration(user, 'social')
    const events = commands().filter(row => row[2] === 'CompleteRegistration')
    assert.equal(events.length, 1)
    assert.equal(events[0][0], 'trackSingle')
    assert.equal(events[0][3].account_type, user.accountType)
    assert.equal(events[0][3].registration_method, 'social')
    assert.equal(events[0][3].company_type, user.profile?.companyType)
    assert.match(events[0][4].eventID, /^[a-f\d-]{36}$/)
    assert.equal(JSON.stringify(events).includes('private@example.test'), false)
    assert.equal('value' in events[0][3], false)
    assert.equal('id' in events[0][3], false)
  }
})

test('rejected consent does not buffer registrations for later replay; staff and demo registrations are excluded', async () => {
  const { api, commands } = await tracking()
  api.trackRegistration(creator)
  api.setMarketingConsent(true)
  assert.equal(commands().some(row => row[2] === 'CompleteRegistration'), false)
  for (const flag of ['isAdmin', 'isModerator', 'isPresentationDemo']) api.trackRegistration({ ...creator, [flag]: true })
  assert.equal(commands().some(row => row[2] === 'CompleteRegistration'), false)
})

test('withdrawal removes pending events, revokes SDK consent, deletes only Meta cookies and blocks future events', async () => {
  const { api, commands, cookies } = await tracking()
  api.trackMetaPageView('/kreatori'); api.setMarketingConsent(true); api.trackRegistration(creator)
  api.setMarketingConsent(false)
  assert.equal(commands().some(row => row[0] === 'trackSingle'), false)
  assert.equal(commands().some(row => row[0] === 'consent' && row[1] === 'grant'), false)
  assert.equal(commands().at(-1)[1], 'revoke')
  const count = commands().length
  api.trackMetaPageView('/kreatori'); api.trackRegistration({ ...creator, id: 9 })
  assert.equal(commands().length, count)
  assert.ok(cookies.some(row => row.startsWith('_fbp=;')))
  assert.ok(cookies.some(row => row.startsWith('_fbc=;')))
  assert.ok(cookies.every(row => !row.includes('wave_session') && !row.includes('_ga')))
})

test('an SDK failure cannot make a successfully created account fail in the interface', async () => {
  const { api, window } = await tracking()
  api.setMarketingConsent(true)
  window.fbq = () => { throw new Error('Blocked') }
  assert.doesNotThrow(() => api.trackRegistration(creator))
})
