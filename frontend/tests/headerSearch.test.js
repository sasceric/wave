import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'
import * as searchQuery from '../src/lib/searchQuery.js'

async function searchSetup(get = async () => ({ data: { creators: [], campaigns: [], companies: [] }, meta: { counts: { creators: 0, campaigns: 0, companies: 0 } } }), log = async () => ({ recorded: true })) {
  const calls = [], logs = [], navigations = []
  const route = reactive({ query: {}, params: {}, meta: {}, fullPath: '/' })
  const locale = ref('bs')
  const setup = await setupView('../src/components/shared/HeaderCreatorSearch.vue', {
    '../../composables/useCurrentUser': { currentUser: ref(null) },
    '../../lib/api': {
      apiGet: async (...args) => { calls.push(args); return get(...args) },
      apiRequest: async (...args) => { logs.push(args); return log(...args) },
      formatMoney: value => String(value),
    },
    '../../routePaths': { localizedRouteName: (name, language) => `${name}-${language}` },
    '../../lib/searchQuery': searchQuery,
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async value => navigations.push(value) }) },
    'vue-i18n': { useI18n: () => ({ locale, t: key => key }) },
  })
  return { ...setup, calls, logs, navigations, route, locale }
}

test('suggestions use Meilisearch buckets, show exact totals and reuse results when switching pills', async () => {
  const totals = { creators: 50, campaigns: 12, companies: 3 }
  const { state, calls, logs } = await searchSetup(async () => ({ data: { creators: Array.from({ length: 10 }, (_, id) => ({ id, title: `Creator ${id}` })), campaigns: [{ id: 2 }], companies: [{ id: 3 }] }, meta: { counts: totals } }))
  state.query.value = '  ljepota  '
  await nextTick()
  await state.loadSuggestions()
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/search?q=ljepota&mode=suggest')
  assert.deepEqual({ ...state.counts.value }, totals)
  assert.equal(state.results.value.length, 10)
  state.type.value = 'companies'
  await nextTick()
  await state.loadSuggestions()
  assert.equal(calls.length, 1)
  assert.equal(state.results.value[0].id, 3)
  assert.equal(logs.length, 0)
})

test('short queries never fetch, navigate or log and count Unicode characters consistently', async () => {
  const { state, calls, logs, navigations } = await searchSetup()
  for (const term of ['', 'a', ' ab ', 'šđ', '😀😀']) {
    state.query.value = term
    await nextTick()
    await state.loadSuggestions()
    await state.handleSubmit()
    assert.equal(state.canSearch.value, false)
    assert.equal(state.loading.value, false)
  }
  assert.equal(calls.length, 0)
  assert.equal(logs.length, 0)
  assert.equal(navigations.length, 0)
  assert.equal(searchQuery.isSearchableQuery('😀😀😀'), true)
  state.query.value = ' UGC '
  await nextTick()
  await state.loadSuggestions()
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/search?q=UGC&mode=suggest')
  await state.handleSubmit()
  assert.equal(logs.length, 1)
  assert.equal(navigations.length, 1)
})

test('shrinking a query below three characters ignores a pending result and clears counts', async () => {
  let resolveRequest
  const { state } = await searchSetup(() => new Promise(resolve => { resolveRequest = resolve }))
  state.query.value = 'test'
  await nextTick()
  const pending = state.loadSuggestions()
  state.query.value = 'te'
  await nextTick()
  resolveRequest({ data: { creators: [{ id: 1 }] }, meta: { counts: { creators: 1 } } })
  await pending
  assert.equal(state.results.value.length, 0)
  assert.deepEqual({ ...state.counts.value }, {})
  assert.equal(state.loading.value, false)
  assert.equal(state.open.value, true)
})

test('submit followed by see all logs once and routes the selected category with localized query', async () => {
  const { state, logs, navigations, locale } = await searchSetup()
  locale.value = 'en'
  state.query.value = '  Natura  '
  await nextTick()
  state.type.value = 'companies'
  await nextTick()
  await state.handleSubmit()
  state.seeAll()
  assert.equal(logs.length, 1)
  assert.equal(logs[0][0], '/search/history')
  assert.equal(logs[0][1].body.query, 'Natura')
  assert.equal(logs[0][1].body.type, 'companies')
  assert.equal(logs[0][1].body.source, 'submit')
  assert.equal(navigations[0].name, 'search-en')
  assert.equal(navigations[0].query.q, 'Natura')
  assert.equal(state.open.value, false)
})

test('a late suggestion response cannot replace a new query', async () => {
  let resolveOld
  const response = id => ({ data: { creators: [{ id }], campaigns: [], companies: [] }, meta: { counts: { creators: id, campaigns: 0, companies: 0 } } })
  const { state } = await searchSetup(path => path.includes('q=old') ? new Promise(resolve => { resolveOld = resolve }) : Promise.resolve(response(2)))
  state.query.value = 'old'
  await nextTick()
  const old = state.loadSuggestions()
  state.query.value = 'new'
  await nextTick()
  await state.loadSuggestions()
  resolveOld(response(99))
  await old
  assert.equal(state.results.value[0].id, 2)
  assert.equal(state.counts.value.creators, 2)
})

test('failed logging never prevents see all, and failed suggestions never show stale rows', async () => {
  const { state, navigations } = await searchSetup(async () => { throw new Error('offline') }, async () => { throw new Error('log unavailable') })
  state.query.value = 'test'
  await nextTick()
  await state.loadSuggestions()
  assert.equal(state.error.value, true)
  assert.equal(state.loading.value, false)
  state.seeAll()
  await nextTick()
  assert.equal(navigations.length, 1)
})

test('submit and search icon navigate directly without loading suggestions', async () => {
  const { state, navigations, calls } = await searchSetup()
  state.query.value = 'ljepota'
  await nextTick()
  await state.handleSubmit()
  assert.equal(calls.length, 0)
  assert.equal(navigations[0].name, 'search-bs')
  assert.equal(navigations[0].query.q, 'ljepota')
  assert.equal(state.open.value, false)
})

test('closing the popup ignores in-flight results and directory navigation closes search', async () => {
  let resolveRequest
  const { state, route } = await searchSetup(() => new Promise(resolve => { resolveRequest = resolve }))
  state.query.value = 'test'
  await nextTick()
  const pending = state.loadSuggestions()
  state.closeSearch()
  resolveRequest({ data: { creators: [{ id: 1 }] }, meta: { counts: { creators: 1 } } })
  await pending
  assert.equal(state.results.value.length, 0)
  route.query = { q: 'new term' }
  route.meta.routeName = 'companies'
  route.fullPath = '/companies?q=new'
  await nextTick()
  await nextTick()
  assert.equal(state.type.value, 'companies')
  assert.equal(state.query.value, 'new term')
  assert.equal(state.open.value, false)
})

test('closing from a result button restores input focus without reopening suggestions', async () => {
  const { state } = await searchSetup()
  state.query.value = 'test'
  await nextTick()
  await state.loadSuggestions()
  state.input.value = { focus: () => state.handleInputFocus() }
  state.closeSearch(true)
  await nextTick()
  assert.equal(state.open.value, false)
  assert.equal(state.loading.value, false)
})

test('a new search starts on the first tab and falls back to the first tab with results', async () => {
  const { state, calls } = await searchSetup(async () => ({ data: { creators: [], campaigns: [{ id: 7, title: 'Kampanja' }], companies: [{ id: 3 }] }, meta: { counts: { creators: 0, campaigns: 1, companies: 1 } } }))
  state.type.value = 'companies'
  await nextTick()
  state.query.value = 'kampanja'
  await nextTick()
  await state.loadSuggestions()
  assert.equal(state.type.value, 'campaigns')
  assert.equal(state.results.value[0].id, 7)
  state.type.value = 'creators'
  await nextTick()
  await state.loadSuggestions()
  assert.equal(state.type.value, 'creators')
  assert.equal(state.results.value.length, 0)
  assert.equal(calls.length, 1)
})
