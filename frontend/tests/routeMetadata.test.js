import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import { pageTitle } from '../src/lib/pageTitle.js'
import { createI18n } from 'vue-i18n'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function fixture() {
  const titles = JSON.parse(await readFile(new URL('../../config/seo_route_titles.json', import.meta.url), 'utf8'))
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url), 'utf8'))
  const context = vm.createContext({})
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/routeMetadata.js', import.meta.url), 'utf8'), { context })
  await module.link(path => path.includes('seo_route_titles')
    ? new vm.SyntheticModule(['default'], function () { this.setExport('default', titles) }, { context })
    : new vm.SyntheticModule(['routeSegments'], function () { this.setExport('routeSegments', routes) }, { context }))
  await module.evaluate()
  return { metadata: module.namespace.routeMetadata, routes, titles }
}

test('every registered private page has an accurate title rather than the not-found fallback in all six locales', async () => {
  const { metadata, routes, titles } = await fixture()
  for (const locale of Object.keys(routes)) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    const t = key => key.split('.').reduce((value, part) => value?.[part], catalog)
    for (const name of Object.keys(titles)) {
      const result = metadata({ meta: { routeName: name }, query: {} }, locale, t, true)
      assert.equal(typeof result.title, 'string', `${locale}: ${name}`)
      assert.notEqual(result.title, catalog.seo.notFoundTitle)
      assert.ok(pageTitle(result.title).endsWith(' | Wave') || pageTitle(result.title).startsWith('Wave | '))
      if (name === 'account' || name.startsWith('account-') || name === 'admin' || name.startsWith('admin-')) assert.equal(result.noindex, true)
    }
    for (const [mode, key] of Object.entries({ login: 'loginTitle', register: 'registerTitle', reset: 'forgotPasswordTitle' })) {
      const result = metadata({ meta: { routeName: 'account' }, query: { mode } }, locale, t, false)
      assert.equal(result.title, catalog.seo[key])
      assert.equal(result.noindex, true)
    }
    const protectedCampaign = metadata({ meta: { routeName: 'account-campaign-create' }, query: {} }, locale, t, false)
    assert.equal(protectedCampaign.title, catalog.seo.loginTitle)
    assert.equal(metadata({ meta: { routeName: 'not-found' } }, locale, t).title, catalog.seo.notFoundTitle)
  }
})

test('title branding stays concise and is never duplicated', () => {
  assert.equal(pageTitle('Creators'), 'Creators | Wave')
  assert.equal(pageTitle('Creators | Wave'), 'Creators | Wave')
  assert.equal(pageTitle('Creators — Wave'), 'Creators | Wave')
  assert.equal(pageTitle('Wave — creator collaborations'), 'Wave | creator collaborations')
})

test('real translations retain the full homepage title instead of interpreting its separator as plural syntax', async () => {
  const { metadata, routes, titles } = await fixture()
  for (const locale of Object.keys(routes)) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    const i18n = createI18n({ legacy: false, locale, messages: { [locale]: catalog } })
    for (const [name, key] of Object.entries(titles)) {
      const raw = key.split('.').reduce((value, part) => value?.[part], catalog)
      const result = metadata({ meta: { routeName: name }, query: {} }, locale, i18n.global.t, true)
      assert.equal(pageTitle(result.title), pageTitle(raw), `${locale}: ${name}`)
    }
    assert.equal(pageTitle(i18n.global.t('meta.title')), pageTitle(catalog.seo.homeTitle))
    i18n.dispose()
  }
})


test('profile routes remain noindex until their public record has loaded', async () => {
  const { metadata } = await fixture()
  for (const name of ['creator-profile', 'company-profile', 'campaign-detail']) {
    assert.equal(metadata({ meta: { routeName: name } }, 'en', key => key).noindex, true)
  }
})

test('app startup keeps server metadata until Router resolves, and only an actual missing route gets the 404 title', async (context) => {
  const { metadata } = await fixture()
  const route = vue.reactive({ name: undefined, path: '/', fullPath: '/', params: {}, query: {}, meta: {} })
  const updates = []
  const watchers = []
  context.after(() => watchers.forEach(stop => stop()))
  await setupView('../src/App.vue', {
    vue: {
      ...vue, onMounted: () => {}, onBeforeUnmount: () => {},
      watch: (...args) => { const stop = vue.watch(...args); watchers.push(stop); return stop },
    },
    'vue-router': { useRoute: () => route, useRouter: () => ({}), RouterView: {}, isNavigationFailure: () => false, NavigationFailureType: {} },
    './lib/seo': { updateSeo: data => updates.push(data) },
    './lib/routeMetadata': { routeMetadata: metadata },
  })
  assert.deepEqual(updates, [])
  Object.assign(route, { name: 'bs-home', meta: { routeName: 'home' } })
  await vue.nextTick()
  // Name and meta resolve together while the initial fullPath remains '/'.
  // The metadata watcher must also react to route resolution itself.
  assert.equal(updates.at(-1)?.title, 'seo.homeTitle')
  Object.assign(route, { name: 'legacy-not-found', path: '/missing', fullPath: '/missing', meta: {} })
  await vue.nextTick()
  assert.equal(updates.at(-1).title, 'seo.notFoundTitle')
})
