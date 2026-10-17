import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'
import * as searchQuery from '../src/lib/searchQuery.js'

async function fixture(get = async () => ({ data: [{ id: 'creator_1_bs', kind: 'creators' }], meta: { counts: { creators: 51, campaigns: 2, companies: 3 }, total: 51 } }), initialQuery = 'ljepota') {
  const route = reactive({ query: { q: initialQuery, type: 'creators', page: '2' } })
  const calls = [], navigations = [], locale = ref('bs'), currentUser = ref(null)
  const result = await setupView('../src/views/SearchView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: value => navigations.push(value) }) },
    'vue-i18n': { useI18n: () => ({ locale, t: key => key }) },
    '../routePaths': { localizedRouteName: (name, language) => `${name}-${language}` },
    '../lib/searchQuery': searchQuery,
    '../composables/useCurrentUser': { currentUser },
    '../lib/api': { apiGet: async (...args) => { calls.push(args); return get(...args) } },
  })
  await nextTick(); await nextTick()
  return { ...result, calls, navigations, route, currentUser }
}

test('search results use paginated indexed endpoint and keep all category totals', async () => {
  const { state, calls, navigations } = await fixture()
  assert.equal(calls[0][0], '/search?q=ljepota&type=creators&page=2')
  assert.equal(state.total.value, 51)
  assert.equal(state.tabs.value[0].count, 56)
  assert.equal(state.page.value, 2)
  state.navigate('campaigns')
  assert.equal(navigations[0].name, 'search-bs')
  assert.deepEqual({ ...navigations[0].query }, { q: 'ljepota', type: 'campaigns' })
  state.navigate('all', 3)
  assert.deepEqual({ ...navigations[1].query }, { q: 'ljepota', page: 3 })
})

test('short result-page queries skip the API and clear previously loaded rows', async () => {
  const { state, route, calls } = await fixture(undefined, ' ab ')
  assert.equal(calls.length, 0)
  assert.equal(state.canSearch.value, false)
  assert.equal(state.loading.value, false)
  route.query.q = 'UGC'
  await nextTick(); await new Promise(resolve => setImmediate(resolve))
  assert.equal(calls.length, 1)
  assert.equal(state.items.value.length, 1)
  route.query.q = 'UG'
  await nextTick()
  assert.equal(calls.length, 1)
  assert.equal(state.items.value.length, 0)
  assert.deepEqual({ ...state.counts.value }, {})
})

test('All groups the same 25 paginated hits by type while retaining full category totals', async () => {
  const rows = Array.from({ length: 25 }, (_, id) => ({ id, kind: ['companies', 'creators', 'campaigns'][id % 3] }))
  const { state, route, calls } = await fixture(async () => ({ data: rows, meta: { counts: { creators: 51, campaigns: 27, companies: 13 }, total: 91 } }))
  route.query.type = 'all'
  await nextTick(); await new Promise(resolve => setImmediate(resolve))
  assert.equal(calls.at(-1)[0], '/search?q=ljepota&type=all&page=2')
  assert.deepEqual(Array.from(state.groups.value, group => group.key), ['creators', 'campaigns', 'companies'])
  assert.deepEqual(Array.from(state.groups.value, group => group.total), [51, 27, 13])
  assert.equal(state.groups.value.reduce((sum, group) => sum + group.items.length, 0), 25)
  assert.equal(new Set(state.groups.value.flatMap(group => group.items.map(item => item.id))).size, 25)
  assert.equal(state.total.value, 91)
})

test('account changes clear old results and a failed request keeps no stale cards', async () => {
  let fail = false
  const { state, currentUser } = await fixture(async () => {
    if (fail) throw new Error('unavailable')
    return { data: [{ id: 'private-to-viewer' }], meta: { counts: {}, total: 1 } }
  })
  fail = true; currentUser.value = { id: 2 }
  await nextTick(); await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.items.value.length, 0)
  assert.equal(state.error.value, 'unavailable')
})
