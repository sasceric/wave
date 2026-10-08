import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

async function regionalFixture() {
  const context = vm.createContext({})
  const countries = JSON.parse(await readFile(new URL('../../config/seo_countries.json', import.meta.url)))
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/regionalSeo.js', import.meta.url), 'utf8'), { context })
  await module.link(() => new vm.SyntheticModule(['default'], function () { this.setExport('default', countries) }, { context }))
  await module.evaluate()
  return module.namespace
}

test('creator guides and how it works have matching routes and complete content in every locale', async () => {
  const { creatorGuideSlugs } = await regionalFixture()
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url)))
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    const copy = catalog.regionalSeo
    assert.ok(routes[locale]['creator-guides'])
    assert.ok(routes[locale]['how-it-works'])
    assert.equal(routes[locale]['creator-guide'], `${routes[locale]['creator-guides']}/:guide`)
    for (const slug of creatorGuideSlugs) {
      const guide = copy.creatorGuides.guides[slug]
      assert.ok(guide.title && guide.intro)
      assert.equal(guide.sections.length, 3)
      assert.ok(guide.sections.every(section => section.heading && section.body.length > 100))
    }
    for (const audience of ['creators', 'companies']) assert.equal(copy.howItWorks[audience].steps.length, 3)
  }
})

test('creator guide metadata rejects unknown slugs and uses distinct page content', async () => {
  const { regionalMetadata } = await regionalFixture()
  const translate = key => key
  assert.equal(regionalMetadata({ meta: { routeName: 'creator-guide' }, params: { guide: 'missing' } }, translate).invalid, true)
  assert.equal(regionalMetadata({ meta: { routeName: 'creator-guide' }, params: { guide: 'create-profile' } }, translate).title, 'regionalSeo.creatorGuides.guides.create-profile.title | Wave')
  assert.equal(regionalMetadata({ meta: { routeName: 'how-it-works' } }, translate).title, 'regionalSeo.howItWorks.title | Wave')
})
