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
  const shared = JSON.parse(await readFile(new URL('../../config/shared_public_routes.json', import.meta.url), 'utf8'))
  const countries = JSON.parse(await readFile(new URL('../../config/seo_countries.json', import.meta.url), 'utf8'))
  const paths = new vm.SourceTextModule(await readFile(new URL('../src/routePaths.js', import.meta.url), 'utf8'), { context })
  await paths.link(path => new vm.SyntheticModule(['default'], function () { this.setExport('default', path.includes('prefixes') ? prefixes : path.includes('shared_public') ? shared : routes) }, { context }))
  const regional = new vm.SourceTextModule(await readFile(new URL('../src/lib/regionalSeo.js', import.meta.url), 'utf8'), { context })
  await regional.link(() => new vm.SyntheticModule(['default'], function () { this.setExport('default', countries) }, { context }))
  const seo = new vm.SourceTextModule(await readFile(new URL('../src/lib/seo.js', import.meta.url), 'utf8'), { context, initializeImportMeta: meta => { meta.env = {} } })
  const title = new vm.SourceTextModule(await readFile(new URL('../src/lib/pageTitle.js', import.meta.url), 'utf8'), { context })
  await title.link(() => {})
  const pageViews = []
  const metaViews = []
  const metaMetrics = new vm.SyntheticModule(['trackMetaPageView'], function () { this.setExport('trackMetaPageView', (path, options) => metaViews.push({ path, ...options })) }, { context })
  const metrics = new vm.SyntheticModule(['trackPageView'], function () {
    this.setExport('trackPageView', (path, options) => pageViews.push({ path, ...options, documentTitle: document.title }))
  }, { context })
  const breadcrumbConfig = JSON.parse(await readFile(new URL('../../config/seo_breadcrumbs.json', import.meta.url), 'utf8'))
  const breadcrumbs = new vm.SyntheticModule(['default'], function () { this.setExport('default', breadcrumbConfig) }, { context })
  const catalog = JSON.parse(await readFile(new URL('../src/locales/bs.json', import.meta.url), 'utf8'))
  const i18n = new vm.SyntheticModule(['default'], function () { this.setExport('default', { global: { t: key => key.split('.').reduce((value, part) => value[part], catalog) } }) }, { context })
  await seo.link(path => path.includes('metaTracking') ? metaMetrics : path.includes('seo_breadcrumbs') ? breadcrumbs : path.includes('i18n') ? i18n : path.includes('privacyMetrics') ? metrics : path.includes('regionalSeo') ? regional : path.includes('pageTitle') ? title : paths)
  await seo.evaluate()
  return { updateSeo: seo.namespace.updateSeo, regionalMetadata: regional.namespace.regionalMetadata, document, nodes, pageViews, metaViews, updateProfileReviewSchema: seo.namespace.updateProfileReviewSchema }
}

test('page views receive the new title after SEO commits it, keep private pages excluded and retain real 404s', async () => {
  const { updateSeo, pageViews } = await seoFixture()
  const update = (name, path, title, extra = {}) => updateSeo({ route: { meta: { routeName: name }, params: {}, path }, locale: 'bs', title, description: '', ...extra })
  update('home', '/', 'Wave — saradnje kreatora i brendova')
  assert.equal(pageViews.at(-1).title, 'Wave | saradnje kreatora i brendova')
  assert.equal(pageViews.at(-1).title, pageViews.at(-1).documentTitle)
  for (const name of ['account', 'account-dashboard', 'admin', 'admin-reports', 'messages', 'verify-email', 'support-track']) {
    update(name, '/private', 'Private')
    assert.equal(pageViews.at(-1).allowed, false, name)
  }
  update('creator-profile', '/kreatori/amina-kovac', 'Creators', { analyticsReady: false, noindex: true })
  assert.equal(pageViews.at(-1).allowed, false)
  update('creator-profile', '/kreatori/amina-kovac', 'Amina Kovač — kreator/ica')
  assert.equal(pageViews.at(-1).allowed, true)
  assert.equal(pageViews.at(-1).title, 'Amina Kovač — kreator/ica | Wave')
  update('not-found', '/does-not-exist', 'Stranica nije pronađena', { noindex: true })
  assert.equal(pageViews.at(-1).allowed, true)
  assert.equal(pageViews.at(-1).title, 'Stranica nije pronađena | Wave')
})

test('SPA navigation retains one self canonical and reciprocal localized alternates on the public origin', async () => {
  const { updateSeo, document } = await seoFixture()
  const expected = {
    bs: 'https://wave.ba/kreatori',
    hr: 'https://wave.ba/hr/kreatori',
    'sr-RS': 'https://wave.ba/rs/kreatori',
    sl: 'https://wave.ba/si/ustvarjalci',
    en: 'https://wave.ba/en/creators',
    'sr-ME': 'https://wave.ba/me/kreatori',
    de: 'https://wave.ba/de/kreative',
    'x-default': 'https://wave.ba/kreatori',
  }
  const languages = { bs: 'bs', hr: 'hr', sr: 'sr-Latn', sl: 'sl', en: 'en', cnr: 'sr-Latn-ME', de: 'de' }
  for (const [locale, language] of Object.entries(languages)) {
    updateSeo({ route: { meta: { routeName: 'creators' }, params: {}, path: '/ignored?q=travel#fragment' }, locale, title: 'Creators', description: 'Find a creator' })
    const canonical = document.head.querySelectorAll('link[rel="canonical"]')
    assert.equal(canonical.length, 1)
    assert.equal(canonical[0].href, expected[({ 'sr-Latn': 'sr-RS', 'sr-Latn-ME': 'sr-ME' })[language] || language])
    assert.equal(document.documentElement.lang, language)
    const alternates = document.head.querySelectorAll('link[rel="alternate"][hreflang]')
    assert.deepEqual(Object.fromEntries(alternates.map(link => [link.hreflang, link.href])), expected)
    const schema = JSON.parse(document.getElementById('wave-schema').textContent)['@graph']
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
  const prefixes = { bs: '', hr: '/hr', sr: '/rs', cnr: '/me', sl: '/si', en: '/en', de: '/de' }
  const segments = { bs: 'influenseri', hr: 'influenceri', sr: 'influenseri', cnr: 'influenseri', sl: 'vplivnezi', en: 'influencers', de: 'influencer' }
  const tags = { bs: 'bs-BA', hr: 'hr-HR', sr: 'sr-RS', cnr: 'sr-ME', sl: 'sl-SI', en: 'en', de: 'de' }
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

test('JSON-LD uses a context-bearing graph compatible with Safari metadata parsing', async () => {
  const { updateSeo, document } = await seoFixture()
  for (const routeName of ['home', 'creators']) {
    updateSeo({ route: { meta: { routeName }, params: {}, path: '/' }, locale: 'bs', title: 'Wave', description: 'Wave' })
    const schema = JSON.parse(document.getElementById('wave-schema').textContent)
    // Safari's injected metadata reader calls this on the top-level object.
    assert.equal(schema['@context'].toLowerCase(), 'https://schema.org')
    assert.equal(Array.isArray(schema), false)
    assert.equal(schema['@graph'][0]['@type'], 'WebSite')
    assert.equal(schema['@graph'][1]['@type'], 'Organization')
    assert.equal(schema['@graph'][2]['@type'], routeName === 'home' ? 'WebPage' : 'CollectionPage')
  }
})

test('profile reviews follow pagination, omit prototypes and leave the aggregate intact', async () => {
  const { updateSeo, updateProfileReviewSchema, document } = await seoFixture()
  const url = 'https://wave.ba/kreatori/amina'
  updateSeo({ route: { meta: { routeName: 'creator-profile' }, params: { slug: 'amina' } }, locale: 'de', title: 'Amina', description: 'Creator',
    mainEntity: { '@type': 'Person', url, makesOffer: { '@type': 'Offer', itemOffered: { '@type': 'Service', aggregateRating: { '@type': 'AggregateRating', reviewCount: 15, ratingValue: 4.5 } } } },
  })
  const rows = [{ id: 11, authorName: 'Brand', comment: 'Excellent work.', rating: 5, createdAt: '2026-10-16T12:00:00Z' },
    { id: 12, authorName: 'Demo brand', comment: 'Fictional review', rating: 5, isPresentationDemo: true }]
  updateProfileReviewSchema('creators', 'other-profile', rows)
  let service = JSON.parse(document.getElementById('wave-schema').textContent)['@graph'][2].mainEntity.makesOffer.itemOffered
  assert.equal(service.review, undefined)
  updateProfileReviewSchema('creators', 'amina', rows)
  service = JSON.parse(document.getElementById('wave-schema').textContent)['@graph'][2].mainEntity.makesOffer.itemOffered
  assert.equal(service.aggregateRating.reviewCount, 15)
  assert.equal(service.review.length, 1)
  assert.equal(service.review[0]['@id'], `${url}#review-11`)
  assert.equal(service.review[0].author['@type'], 'Organization')
  updateProfileReviewSchema('creators', 'amina', [])
  service = JSON.parse(document.getElementById('wave-schema').textContent)['@graph'][2].mainEntity.makesOffer.itemOffered
  assert.equal(service.review, undefined)
  const nodes = JSON.parse(document.getElementById('wave-schema').textContent)['@graph']
  assert.equal(nodes.find(node => node['@type'] === 'BreadcrumbList').itemListElement[1].item, 'https://wave.ba/de/kreative')
})


test('Meta page views allow resolved public routes and exclude account, messages, token, search and missing pages', async () => {
  const { updateSeo, metaViews } = await seoFixture()
  for (const name of ['home', 'creators', 'account', 'messages', 'verify-email', 'search', 'not-found']) {
    updateSeo({ route: { meta: { routeName: name }, params: {}, path: '/' }, locale: 'bs', title: 'Wave', description: '' })
    assert.equal(Boolean(metaViews.at(-1).allowed), ['home', 'creators'].includes(name), name)
  }
})
