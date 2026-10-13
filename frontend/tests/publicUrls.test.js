import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { ref, reactive, nextTick } from 'vue'
import { setupView } from './setupView.js'

async function paths() {
  const context = vm.createContext({ Set, Object, encodeURIComponent })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/routePaths.js', import.meta.url), 'utf8'), { context })
  await module.link(async specifier => {
    const value = JSON.parse(await readFile(new URL(specifier, new URL('../src/routePaths.js', import.meta.url)), 'utf8'))
    return new vm.SyntheticModule(['default'], function () { this.setExport('default', value) }, { context })
  })
  await module.evaluate()
  return module.namespace
}

test('every language links to a single creator/company/campaign URL while directories remain localized', async () => {
  const { localizedPath, localizedRouteName, legacyLocalizedDetailPath } = await paths()
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']) {
    assert.equal(localizedPath('creator-profile', locale, { slug: 'amina-kovac-1' }), '/kreatori/amina-kovac-1')
    assert.equal(localizedPath('company-profile', locale, { slug: 'agency' }), '/kompanije/agency')
    assert.equal(localizedPath('campaign-detail', locale, { slug: 'campaign' }), '/kampanje/campaign')
    assert.equal(localizedRouteName('creator-profile', locale), 'creator-profile')
  }
  assert.equal(localizedPath('creators', 'en'), '/en/creators')
  assert.equal(legacyLocalizedDetailPath('creator-profile', 'en'), '/en/creators/:slug')
})

test('language switching on a shared detail page updates locale without changing its URL', async () => {
  const selected = [], navigated = []
  const { state } = await setupView('../src/components/shared/LanguageSwitcher.vue', {
    '../../i18n': { localeNames: { bs: 'Bosanski', en: 'English' }, setLocale: async value => selected.push(value) },
    '../../routePaths': await paths(),
    'vue-router': { useRoute: () => ({ meta: { routeName: 'creator-profile' }, params: { slug: 'amina' }, query: {} }), useRouter: () => ({ replace: target => navigated.push(target) }) },
  })
  await state.selectLocale('en')
  assert.deepEqual(selected, ['en'])
  assert.equal(navigated.length, 0)
})

test('URL editing shows the exact destination, locks subsequent changes and discards results from a previous account', async () => {
  const currentUser = ref({ id: 1 })
  const props = reactive({ kind: 'creator', profile: { id: 10, slug: 'amina', publicUrlChangeAvailableAt: null } })
  const pending = []
  const { state, emitted } = await setupView('../src/components/account/PublicUrlAction.vue', {
    '../../composables/useCurrentUser': { currentUser },
    '../../lib/seo': { getSeoOrigin: () => 'https://wave.ba' },
    '../../routePaths': await paths(),
    '../../lib/api': { apiRequest: (path, options) => new Promise(resolve => pending.push({ path, options, resolve })), formatDate: String },
  }, props, { setInterval, clearInterval, URL })
  assert.equal(state.url.value, 'https://wave.ba/kreatori/amina')
  state.show(); state.slug.value = 'amina-kovac'
  const saving = state.save()
  assert.equal(pending[0].path, '/me/public-url')
  pending[0].resolve({ userId: 1, data: { slug: 'amina-kovac', path: '/kreatori/amina-kovac', url: 'https://wave.ba/kreatori/amina-kovac', availableAt: new Date(Date.now() + 14 * 86400000).toISOString() } })
  await saving
  assert.equal(emitted[0][0], 'changed')
  state.show()
  assert.ok(state.locked.value)
  await state.save()
  assert.equal(pending.length, 1)
  state.open.value = false
  await nextTick()
  props.profile = { ...props.profile, slug: 'different', publicUrlChangeAvailableAt: null }
  await nextTick()
  state.show(); state.slug.value = 'another'
  const stale = state.save()
  currentUser.value = { id: 2 }
  await nextTick()
  pending[1].resolve({ userId: 1, data: { slug: 'another' } })
  await stale
  assert.equal(state.saved.value, null)
  assert.equal(emitted.length, 1)
})
