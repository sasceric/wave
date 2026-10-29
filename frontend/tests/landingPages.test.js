import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'
import { createI18n } from 'vue-i18n'

// regionalSeo.js imports JSON config the way Vite does; link it to the real files.
const config = name => JSON.parse(readFileSync(new URL(`../../config/${name}.json`, import.meta.url), 'utf8'))
const context = vm.createContext({})
const regional = new vm.SourceTextModule(readFileSync(new URL('../src/lib/regionalSeo.js', import.meta.url), 'utf8'), { context })
await regional.link(path => new vm.SyntheticModule(['default'], function () { this.setExport('default', config(path.includes('seo_topics') ? 'seo_topics' : 'seo_countries')) }, { context }))
await regional.evaluate()
const { countries, creatorLandingPages, topicCopy, topicForSlug, topics } = regional.namespace
// Values created inside the vm context have another realm's prototypes; compare plain copies.
const plain = value => JSON.parse(JSON.stringify(value ?? null))

const catalog = locale => JSON.parse(readFileSync(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))

test('every landing page has copy and a country phrase in every language', () => {
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']) {
    const { regionalSeo } = catalog(locale)
    for (const code of Object.keys(countries)) assert.ok(regionalSeo.countries[code].in, `${locale} ${code}`)
    for (const key of Object.values(topics.categories)) {
      for (const field of ['title', 'description', 'intro']) assert.match(regionalSeo.topics.categories[key][field], /\{in\}/, `${locale} ${key} ${field}`)
    }
    for (const field of ['title', 'description', 'intro']) {
      assert.match(regionalSeo.topics.ugc[field], /\{in\}/)
      assert.match(regionalSeo.topics.city[field], /\{in\}/)
    }
  }
})

test('landing pages resolve area, UGC and city slugs and fill the right phrase', () => {
  const i18n = createI18n({ legacy: false, locale: 'bs', messages: { bs: catalog('bs'), en: catalog('en') } })
  const t = i18n.global.t
  assert.deepEqual(plain(topicForSlug('BA', 'ljepota')), { slug: 'ljepota', kind: 'category', key: 'beauty' })
  assert.equal(topicForSlug('BA', 'ugc-kreatori').key, 'ugc-creator')
  assert.equal(topicForSlug('BA', 'zagreb'), null, 'cities belong to their own country')
  assert.equal(topicForSlug('BA', 'nepostojeca'), null)
  assert.equal(topicCopy(t, 'bs', 'BA', topicForSlug('BA', 'ljepota')).title, 'Influenseri za ljepotu i kozmetiku u Bosni i Hercegovini')
  assert.equal(topicCopy(t, 'bs', 'BA', topicForSlug('BA', 'banja-luka')).title, 'Influenseri u Banjoj Luci za saradnju')
  i18n.global.locale.value = 'en'
  assert.equal(topicCopy(t, 'en', 'BA', topicForSlug('BA', 'sarajevo')).title, 'Influencers in Sarajevo for collaborations')
  i18n.dispose()
})

test('a creator links to the populated landing pages of their city, area and UGC type', () => {
  const items = [
    { slug: 'ljepota', kind: 'category', key: 'beauty', count: 4 },
    { slug: 'ugc-kreatori', kind: 'type', key: 'ugc-creator', count: 3 },
    { slug: 'banja-luka', kind: 'city', key: 'banja-luka', count: 3 },
  ]
  const links = creatorLandingPages(items, 'BA', { city: 'Banjaluka, BiH', category: 'Beauty', creatorTypes: ['ugc-creator'] })
  assert.deepEqual(plain(links), { city: { slug: 'banja-luka', country: 'bosna-i-hercegovina' }, category: { slug: 'ljepota', country: 'bosna-i-hercegovina' }, ugc: { slug: 'ugc-kreatori', country: 'bosna-i-hercegovina' } })
  assert.deepEqual(plain(creatorLandingPages(items, 'BA', { city: 'Mostar', category: 'Food', creatorTypes: [] })), {})
})
