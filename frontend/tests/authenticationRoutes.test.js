import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

async function paths() {
  const context = vm.createContext({})
  const module = new vm.SourceTextModule(await readFile(new URL('../src/routePaths.js', import.meta.url), 'utf8'), { context })
  await module.link(async path => {
    const data = JSON.parse(await readFile(new URL(path, new URL('../src/routePaths.js', import.meta.url)), 'utf8'))
    return new vm.SyntheticModule(['default'], function () { this.setExport('default', data) }, { context })
  })
  await module.evaluate()
  return module.namespace
}

test('all seven languages have clean localized authentication destinations', async () => {
  const { localizedPath, authenticationDestination } = await paths()
  const expected = {
    bs: ['/prijava', '/registracija'], hr: ['/hr/prijava', '/hr/registracija'],
    sr: ['/rs/prijava', '/rs/registracija'], cnr: ['/me/prijava', '/me/registracija'],
    sl: ['/si/prijava', '/si/registracija'], en: ['/en/login', '/en/register'],
    de: ['/de/anmelden', '/de/registrieren'],
  }
  for (const [locale, [login, register]] of Object.entries(expected)) {
    assert.equal(localizedPath('login', locale), login)
    assert.equal(localizedPath('register', locale), register)
  }
  const old = { name: 'account', query: { mode: 'register', accountType: 'company', returnTo: '/kampanje/test' }, hash: '#step' }
  const target = authenticationDestination(old)
  assert.equal(target.name, 'register')
  assert.equal(target.query.mode, undefined)
  assert.equal(target.query.accountType, 'company')
  assert.equal(target.query.returnTo, '/kampanje/test')
  assert.equal(target.hash, '#step')
  assert.equal(old.query.mode, 'register')
  assert.equal(authenticationDestination({ name: 'account', query: { mode: '__proto__' } }).name, 'account')
  assert.equal(authenticationDestination({ name: 'account', query: { mode: 'reset' } }).name, 'forgot-password')
})

test('authentication form follows the route and switches pages without a mode parameter', async () => {
  const route = reactive({ meta: { authMode: 'register' }, query: { accountType: 'company', returnTo: '/kampanje/test' } })
  const changes = []
  const { state } = await setupView('../src/components/account/AccountAccessPanel.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ replace: async to => changes.push(to) }) },
    '../../routePaths': { localizedRouteName: (name, locale) => `${name}-${locale}` },
  })
  assert.equal(state.mode.value, 'register')
  assert.equal(state.form.accountType, 'company')
  route.meta.authMode = 'login'
  await nextTick()
  assert.equal(state.mode.value, 'login')
  state.changeMode('reset')
  assert.equal(changes.at(-1).name, 'forgot-password-bs')
  assert.equal(changes.at(-1).query.mode, undefined)
  assert.equal(changes.at(-1).query.returnTo, '/kampanje/test')
})
