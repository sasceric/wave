import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

async function searchSetup(get = async () => ({ data: [], meta: { total: 0 } }), log = async () => ({ recorded: true })) {
  const calls = [], logs = [], navigations = []
  const route = reactive({ query: {}, params: {}, meta: {}, fullPath: '/' })
  const locale = ref('bs')
  const setup = await setupView('../src/components/shared/HeaderCreatorSearch.vue', {
    '../../lib/api': {
      apiGet: async (...args) => { calls.push(args); return get(...args) },
      apiRequest: async (...args) => { logs.push(args); return log(...args) },
      formatMoney: value => String(value),
    },
    '../../routePaths': { localizedRouteName: (name, language) => `${name}-${language}` },
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async value => navigations.push(value) }) },
    'vue-i18n': { useI18n: () => ({ locale, t: key => key }) },
  })
  return { ...setup, calls, logs, navigations, route, locale }
}

test('suggestions query only the chosen directory and do not log keystrokes', async () => {
  const { state, calls, logs } = await searchSetup(async () => ({ data: [{ id: 1, slug: 'summer' }], meta: { total: 12 } }))
  state.query.value = '  ljetna kampanja  '
  state.type.value = 'campaigns'
  await nextTick()
  await state.loadSuggestions()
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], '/campaigns?q=ljetna+kampanja&limit=5&view=card')
  assert.equal(state.counts.value.campaigns, 12)
  assert.equal(logs.length, 0)
})

test('submit followed by see all logs once and routes the selected category with localized query', async () => {
  const { state, logs, navigations, locale } = await searchSetup()
  locale.value = 'en'
  state.type.value = 'companies'
  state.query.value = '  Natura  '
  await nextTick()
  await state.handleSubmit()
  state.seeAll()
  assert.equal(logs.length, 1)
  assert.equal(logs[0][0], '/search/history')
  assert.equal(logs[0][1].body.query, 'Natura')
  assert.equal(logs[0][1].body.type, 'companies')
  assert.equal(logs[0][1].body.source, 'submit')
  assert.equal(navigations[0].name, 'companies-en')
  assert.equal(navigations[0].query.q, 'Natura')
  assert.equal(state.open.value, false)
})

test('a late response for a previous type cannot replace current results', async () => {
  let resolveOld
  const { state } = await searchSetup(path => path.startsWith('/creators') ? new Promise(resolve => { resolveOld = resolve }) : Promise.resolve({ data: [{ id: 2 }], meta: { total: 1 } }))
  state.query.value = 'test'
  await nextTick()
  const old = state.loadSuggestions()
  state.type.value = 'campaigns'
  await nextTick()
  await state.loadSuggestions()
  resolveOld({ data: [{ id: 1 }], meta: { total: 99 } })
  await old
  assert.equal(state.results.value[0].id, 2)
  assert.equal(state.counts.value.creators, undefined)
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

test('closing the popup ignores in-flight results and directory navigation closes search', async () => {
  let resolveRequest
  const { state, route } = await searchSetup(() => new Promise(resolve => { resolveRequest = resolve }))
  state.query.value = 'test'
  await nextTick()
  const pending = state.loadSuggestions()
  state.closeSearch()
  resolveRequest({ data: [{ id: 1 }], meta: { total: 1 } })
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
