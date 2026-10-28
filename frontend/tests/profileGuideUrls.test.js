import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import { accountAccessRedirect } from '../src/lib/accountAccess.js'
import { profileKind, profileStrength, profileSteps } from '../src/lib/profileStrength.js'

async function routesFixture() {
  const context = vm.createContext({})
  const paths = new vm.SourceTextModule(await readFile(new URL('../src/routePaths.js', import.meta.url), 'utf8'), { context })
  await paths.link(async name => {
    const data = JSON.parse(await readFile(new URL(name, import.meta.url), 'utf8'))
    return new vm.SyntheticModule(['default'], function () { this.setExport('default', data) }, { context })
  })
  await paths.evaluate()
  const metadata = new vm.SourceTextModule(await readFile(new URL('../src/lib/routeMetadata.js', import.meta.url), 'utf8'), { context })
  await metadata.link(async name => {
    if (name.endsWith('routePaths')) return paths
    const data = JSON.parse(await readFile(new URL('../../config/seo_route_titles.json', import.meta.url), 'utf8'))
    return new vm.SyntheticModule(['default'], function () { this.setExport('default', data) }, { context })
  })
  await metadata.evaluate()
  return { ...paths.namespace, routeMetadata: metadata.namespace.routeMetadata }
}

test('profile guides have stable public paths in every language and no query selectors', async () => {
  const { localizedPath, routeSegments, routeMetadata } = await routesFixture()
  const expected = { creator: '/racun/vodic-za-profil/kreator', agency: '/racun/vodic-za-profil/agencija', brand: '/racun/vodic-za-profil/brend' }
  for (const [kind, path] of Object.entries(expected)) {
    assert.equal(localizedPath(`profile-guide-${kind}`, 'bs'), path)
    for (const locale of Object.keys(routeSegments)) {
      const name = `profile-guide-${kind}`
      assert.equal(localizedPath(name, locale).includes('?'), false)
      const meta = routeMetadata({ meta: { routeName: name }, query: {} }, locale, key => key)
      assert.equal(meta.noindex, false)
      assert.equal(meta.title, `profileGuide.${kind}.title`)
      assert.equal(meta.description, `profileGuide.${kind}.lead`)
      assert.equal(accountAccessRedirect({ meta: { routeName: name } }, { profileComplete: false, approved: false, emailVerified: false }), null)
    }
  }
})

test('guide content follows the URL audience even for another account type or a conflicting legacy query', async () => {
  const route = reactive({ meta: { routeName: 'profile-guide-brand' }, query: { type: 'creator' } })
  const { state, currentUser } = await setupView('../src/views/AccountProfileGuideView.vue', {
    'vue-router': { useRoute: () => route },
    '../lib/profileStrength': { profileKind, profileStrength, profileSteps },
  })
  assert.equal(state.kind.value, 'brand')
  assert.equal(state.strength.value, null)
  currentUser.value = { id: 1, accountType: 'company', profile: { companyType: 'brand' } }
  await nextTick()
  assert.equal(state.strength.value.kind, 'brand')
  route.meta.routeName = 'profile-guide-agency'
  await nextTick()
  assert.equal(state.kind.value, 'agency')
  assert.equal(state.strength.value, null)
  route.meta.routeName = 'profile-guide-creator'
  currentUser.value = null
  await nextTick()
  assert.equal(state.kind.value, 'creator')
})
