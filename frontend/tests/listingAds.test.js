import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { emptyListingAd, listingEntries, fitListingEntries, AD_KINDS, AD_PALETTES } from '../src/lib/listingAds.js'
import { setupView } from './setupView.js'

const items = count => Array.from({ length: count }, (_, id) => ({ id: id + 1 }))
const ad = (id, span, placements) => ({ ...emptyListingAd(), id, span, placements })

test('one- and two-space ads land at exact desktop row/column without replacing results', () => {
  const results = items(25)
  const entries = listingEntries(results, [ad(1, 2, [{ row: 2, column: 2 }, { row: 5, column: 1 }]), ad(2, 1, [{ row: 1, column: 4 }])])
  const positions = []
  let slot = 0
  for (const entry of entries) {
    if (entry.ad) positions.push([entry.ad.id, Math.floor(slot / 4) + 1, slot % 4 + 1])
    slot += entry.ad?.span || 1
  }
  assert.deepEqual(positions, [[2, 1, 4], [1, 2, 2], [1, 5, 1]])
  assert.deepEqual(entries.filter(entry => entry.item).map(entry => entry.item), results)
  assert.equal(new Set(entries.map(entry => entry.key)).size, entries.length)
})

test('load more keeps existing cards stable and only reveals reachable ad placements', () => {
  const ads = [ad(1, 2, [{ row: 2, column: 1 }, { row: 20, column: 1 }])]
  assert.deepEqual(listingEntries([], ads), [])
  const short = listingEntries(items(10), ads)
  const expanded = listingEntries(items(100), ads)
  assert.equal(short.filter(entry => entry.ad).length, 1)
  assert.equal(expanded.filter(entry => entry.ad).length, 2)
  assert.deepEqual(expanded.slice(0, short.length), short)
  assert.deepEqual(listingEntries(items(3), ads).map(entry => entry.item.id), [1, 2, 3])
})

async function admin(apiRequest) {
  const currentUser = vue.ref({ isAdmin: true })
  const { state } = await setupView('../src/views/AdminListingAdsView.vue', {
    '../composables/useCurrentUser': { currentUser },
    '../lib/api': { apiGet: async () => ({ data: [] }), apiRequest, apiUpload: async () => ({ data: { id: 8, url: '/image.webp' } }) },
    '../lib/listingAds': { emptyListingAd, listingEntries, AD_KINDS, AD_PALETTES },
  }, {}, { AbortController })
  await vue.nextTick()
  return state
}

test('admin saves a reusable draft, and toggles become visible only after server success', async () => {
  const calls = []
  const state = await admin(async (url, options) => { calls.push([url, options]); return { data: { ...options.body, id: 12 } } })
  state.edit()
  assert.equal(state.draft.value.enabled, false)
  state.draft.value.title = 'Test'
  state.addPlacement()
  await state.persist(state.draft.value, true)
  assert.equal(calls[0][0], '/admin/listing-ads/creators')
  assert.equal(calls[0][1].body.placements.length, 2)
  assert.equal(state.open.value, false)
  assert.equal(state.rows.value[0].id, 12)
  await state.persist({ ...state.rows.value[0], enabled: true })
  assert.equal(calls[1][0], '/admin/listing-ads/creators/12')
  assert.equal(state.rows.value[0].enabled, true)
})

test('rejected activation leaves the saved row unchanged and explains overlapping placements', async () => {
  const state = await admin(async () => { throw Object.assign(new Error('Conflict'), { code: 'listingAds.overlap' }) })
  state.rows.value = [{ ...emptyListingAd(), id: 3 }]
  await state.persist({ ...state.rows.value[0], enabled: true })
  assert.equal(state.rows.value[0].enabled, false)
  assert.equal(state.open.value, false)
  assert.equal(state.error.value, '')
  assert.equal(state.busy.value, false)
})

test('a wide campaign ad can follow the only result in a partial row', () => {
  const entries = listingEntries(items(1), [ad(1, 2, [{ row: 1, column: 2 }])])
  assert.equal(entries.length, 2)
  assert.equal(entries[1].ad.id, 1)
})

test('two-column screens fill the row before a wide ad without reordering results or ads', () => {
  const desktop = listingEntries(items(20), [ad(1, 2, [{ row: 1, column: 2 }, { row: 3, column: 2 }])])
  const mobile = fitListingEntries(desktop, 2)
  assert.deepEqual(mobile.filter(entry => entry.item).map(entry => entry.item.id), items(20).map(item => item.id))
  assert.deepEqual(mobile.filter(entry => entry.ad).map(entry => entry.key), desktop.filter(entry => entry.ad).map(entry => entry.key))
  assert.deepEqual(mobile.slice(0, 3).map(entry => entry.item?.id || 'ad'), [1, 2, 'ad'])
  let slot = 0
  for (const entry of mobile) {
    if (entry.ad?.span === 2) assert.equal(slot % 2, 0)
    slot += entry.ad?.span || 1
  }
  assert.deepEqual(fitListingEntries(desktop, 1), desktop)
  assert.deepEqual(fitListingEntries(desktop, 4), desktop)
})

test('public ad cards escape copy and render a separate secondary support link', async () => {
  const { readFile } = await import('node:fs/promises')
  const { parse } = await import('@vue/compiler-sfc')
  const { compile } = await import('@vue/compiler-dom')
  const { renderToString } = await import('vue/server-renderer')
  const { descriptor } = parse(await readFile(new URL('../src/components/shared/ListingAdCard.vue', import.meta.url), 'utf8'))
  const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)
  const creative = { ...emptyListingAd('campaigns'), title: '<script>bad()</script>', buttonLabel: 'Create', url: '/racun/kampanje/nova', helpLabel: 'Need help?', helpUrl: '/podrska', span: 2 }
  const app = vue.createSSRApp({ setup: () => ({ ad: creative, preview: false, AD_PALETTES, t: key => key }), render })
  app.component('ArrowUpRight', { render: () => vue.h('svg') })
  app.component('CardImage', { render: () => null })
  const html = await renderToString(app)
  assert.match(html, /listing-ad--wide/)
  assert.match(html, /&lt;script&gt;bad\(\)&lt;\/script&gt;/)
  assert.match(html, /class="listing-ad__help" href="\/podrska"/)
  assert.match(html, /href="\/racun\/kampanje\/nova" rel="sponsored noopener"/)
})
