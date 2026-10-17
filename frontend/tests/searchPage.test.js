import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

async function fixture(get = async () => ({ data: [{ id: 'creator_1_bs' }], meta: { counts: { creators: 51, campaigns: 2, companies: 3 }, total: 51 } })) {
  const route = reactive({ query: { q: 'ljepota', type: 'creators', page: '2' } })
  const calls = [], navigations = [], locale = ref('bs'), currentUser = ref(null)
  const result = await setupView('../src/views/SearchView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: value => navigations.push(value) }) },
    'vue-i18n': { useI18n: () => ({ locale, t: key => key }) },
    '../routePaths': { localizedRouteName: (name, language) => `${name}-${language}` },
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
