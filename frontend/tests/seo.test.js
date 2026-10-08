import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

async function seoFixture() {
  const nodes = []
  function matches(node, selector) {
    if (node.tag !== selector.match(/^\w+/)[0]) return false
    return [...selector.matchAll(/\[([^\]=]+)(?:="([^"]*)")?\]/g)].every(([, key, value]) => (
      value === undefined ? node[key] !== undefined : node[key] === value
    ))
  }
  const document = {
    documentElement: {},
    head: {
      append: node => nodes.push(node),
      querySelector: selector => nodes.find(node => matches(node, selector)),
      querySelectorAll: selector => nodes.filter(node => matches(node, selector)),
    },
    createElement: tag => ({ tag, dataset: {}, setAttribute(key, value) { this[key] = value }, remove() { nodes.splice(nodes.indexOf(this), 1) } }),
    getElementById: id => nodes.find(node => node.id === id),
  }
  const origin = document.createElement('meta')
  origin.name = 'wave:origin'
  origin.content = 'https://wave.ba'
  document.head.append(origin)
  const context = vm.createContext({ document, window: { location: { origin: 'http://127.0.0.1:5173' } }, URL })
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url), 'utf8'))
  const prefixes = JSON.parse(await readFile(new URL('../../config/localized_route_prefixes.json', import.meta.url), 'utf8'))
  const countries = JSON.parse(await readFile(new URL('../../config/seo_countries.json', import.meta.url), 'utf8'))
  const paths = new vm.SourceTextModule(await readFile(new URL('../src/routePaths.js', import.meta.url), 'utf8'), { context })
  await paths.link(path => new vm.SyntheticModule(['default'], function () { this.setExport('default', path.includes('prefixes') ? prefixes : routes) }, { context }))
  const regional = new vm.SourceTextModule(await readFile(new URL('../src/lib/regionalSeo.js', import.meta.url), 'utf8'), { context })
  await regional.link(() => new vm.SyntheticModule(['default'], function () { this.setExport('default', countries) }, { context }))
  const seo = new vm.SourceTextModule(await readFile(new URL('../src/lib/seo.js', import.meta.url), 'utf8'), { context, initializeImportMeta: meta => { meta.env = {} } })
  await seo.link(path => path.includes('regionalSeo') ? regional : paths)
  await seo.evaluate()
  return { updateSeo: seo.namespace.updateSeo, regionalMetadata: regional.namespace.regionalMetadata, document, nodes }
}

test('SPA navigation retains one self canonical and reciprocal localized alternates on the public origin', async () => {
  const { updateSeo, document } = await seoFixture()
  const expected = {
    bs: 'https://wave.ba/kreatori',
    hr: 'https://wave.ba/hr/kreatori',
    'sr-RS': 'https://wave.ba/rs/kreatori',
    sl: 'https://wave.ba/si/ustvarjalci',
    en: 'https://wave.ba/en/creators',
    'sr-ME': 'https://wave.ba/me/kreatori',
    'x-default': 'https://wave.ba/kreatori',
  }
  const languages = { bs: 'bs', hr: 'hr', sr: 'sr-Latn', sl: 'sl', en: 'en', cnr: 'sr-Latn-ME' }
  for (const [locale, language] of Object.entries(languages)) {
    updateSeo({ route: { meta: { routeName: 'creators' }, params: {}, path: '/ignored?q=travel#fragment' }, locale, title: 'Creators', description: 'Find a creator' })
    const canonical = document.head.querySelectorAll('link[rel="canonical"]')
    assert.equal(canonical.length, 1)
    assert.equal(canonical[0].href, expected[({ 'sr-Latn': 'sr-RS', 'sr-Latn-ME': 'sr-ME' })[language] || language])
    assert.equal(document.documentElement.lang, language)
    const alternates = document.head.querySelectorAll('link[rel="alternate"][hreflang]')
    assert.deepEqual(Object.fromEntries(alternates.map(link => [link.hreflang, link.href])), expected)
    const schema = JSON.parse(document.getElementById('wave-schema').textContent)
    assert.equal(schema[2].url, expected[({ 'sr-Latn': 'sr-RS', 'sr-Latn-ME': 'sr-ME' })[language] || language])
    assert.equal(schema[2].inLanguage, language)
  }
})

test('private and unknown SPA routes clear public alternates and schema without leaving a stale canonical', async () => {
  const { updateSeo, document } = await seoFixture()
  const update = (routeName, noindex = false) => updateSeo({ route: { meta: { routeName }, params: {}, path: '/unknown' }, locale: 'bs', title: 'Wave', description: 'Wave', noindex })
  update('home')
  update('account', true)
  assert.equal(document.head.querySelector('link[rel="canonical"]').href, 'https://wave.ba/racun')
  assert.equal(document.head.querySelectorAll('link[rel="alternate"][hreflang]').length, 0)
  assert.equal(document.getElementById('wave-schema'), undefined)
  update('home')
  update('not-found')
  assert.equal(document.head.querySelector('meta[name="robots"]').content, 'noindex, nofollow')
  assert.equal(document.head.querySelector('link[rel="canonical"]'), undefined)
  assert.equal(document.head.querySelector('meta[property="og:url"]'), undefined)
  assert.equal(document.head.querySelectorAll('link[rel="alternate"][hreflang]').length, 0)
  assert.equal(document.getElementById('wave-schema'), undefined)
})

test('country directories retain the same country across language alternates and invalid content clears its canonical', async () => {
  const { updateSeo, regionalMetadata, document } = await seoFixture()
  const countries = JSON.parse(await readFile(new URL('../../config/seo_countries.json', import.meta.url), 'utf8'))
  const prefixes = { bs: '', hr: '/hr', sr: '/rs', cnr: '/me', sl: '/si', en: '/en' }
  const segments = { bs: 'influenseri', hr: 'influenceri', sr: 'influenseri', cnr: 'influenseri', sl: 'vplivnezi', en: 'influencers' }
  const tags = { bs: 'bs-BA', hr: 'hr-HR', sr: 'sr-RS', cnr: 'sr-ME', sl: 'sl-SI', en: 'en' }
  for (const [code, country] of Object.entries(countries)) {
    for (const locale of Object.keys(prefixes)) {
      const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
      const t = key => key.split('.').reduce((value, part) => value[part], catalog)
      const route = { meta: { routeName: 'country-creators' }, params: { country: country.slug } }
      const metadata = regionalMetadata(route, t)
      assert.equal(metadata.title, `${catalog.regionalSeo.countries[code].title} | Wave`)
      updateSeo({ route, locale, ...metadata })
      assert.equal(document.head.querySelector('link[rel="canonical"]').href, `https://wave.ba${prefixes[locale]}/${segments[locale]}/${country.slug}`)
      const expected = Object.fromEntries(Object.keys(prefixes).map(language => [tags[language], `https://wave.ba${prefixes[language]}/${segments[language]}/${country.slug}`]))
      expected['x-default'] = `https://wave.ba/influenseri/${country.slug}`
      assert.deepEqual(Object.fromEntries(document.head.querySelectorAll('link[hreflang]').map(link => [link.hreflang, link.href])), expected)
    }
  }
  for (const route of [{ meta: { routeName: 'country-creators' }, params: { country: 'invalid' } }, { meta: { routeName: 'seo-guide' }, params: { guide: 'invalid' } }]) {
    assert.equal(regionalMetadata(route, () => 'unexpected').invalid, true)
  }
  updateSeo({ route: { meta: { routeName: 'country-creators' }, params: { country: 'srbija' } }, locale: 'sr', title: 'Wave', description: 'Wave', noindex: true })
  assert.equal(document.head.querySelectorAll('link[hreflang]').length, 0)
  assert.equal(document.getElementById('wave-schema'), undefined)
})
