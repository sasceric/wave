import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

const paths = { 'creator-profile': 'kreatori', 'company-profile': 'kompanije', 'campaign-detail': 'kampanje' }
async function setup(navigator, props = reactive({ routeName: 'creator-profile', slug: 'amina-kovac', title: 'Amina Kovač' })) {
  const locale = ref('bs')
  const result = await setupView('../src/components/shared/PublicShareButton.vue', {
    'vue-i18n': { useI18n: () => ({ locale, t: key => key }) },
    '../../routePaths': { localizedPath: (name, lang, { slug }) => `${lang === 'bs' ? '' : `/${lang}`}/${paths[name]}/${encodeURIComponent(slug)}` },
  }, props, { navigator, window: { location: { origin: 'https://wave.ba', search: '?token=private', hash: '#private' } } })
  return { ...result, locale, props }
}

test('native sharing uses each public detail URL without query strings or fragments', async () => {
  for (const routeName of Object.keys(paths)) {
    const calls = []
    const { state } = await setup({ share: async payload => calls.push(payload) }, reactive({ routeName, slug: 'public-profile', title: 'Public name' }))
    assert.equal(calls.length, 0)
    await state.share()
    assert.equal(calls.length, 1)
    assert.equal(calls[0].url, `https://wave.ba/${paths[routeName]}/public-profile`)
    assert.equal(calls[0].title, 'Public name')
    assert.equal(state.open.value, false)
  }
})

test('canceling the native sheet is quiet and repeat taps do not open another sheet', async () => {
  let reject
  let count = 0
  const { state } = await setup({ share: () => { count++; return new Promise((resolve, fail) => { reject = fail }) } })
  const pending = state.share()
  await state.share()
  assert.equal(count, 1)
  reject({ name: 'AbortError' })
  await pending
  assert.equal(state.open.value, false)
  assert.equal(state.busy.value, false)
})

test('unsupported or failed native sharing exposes a selectable URL with truthful copy feedback', async () => {
  const writes = []
  const { state } = await setup({ clipboard: { writeText: async value => writes.push(value) } })
  await state.share()
  assert.equal(state.open.value, true)
  await state.copy()
  assert.deepEqual(writes, ['https://wave.ba/kreatori/amina-kovac'])
  assert.equal(state.copied.value, true)
  const blocked = await setup({ share: async () => { throw new Error('Denied') } })
  await blocked.state.share()
  assert.equal(blocked.state.open.value, true)
  await blocked.state.copy()
  assert.equal(blocked.state.copied.value, false)
  assert.equal(blocked.state.copyError.value, true)
})

test('fallback state and late errors cannot carry over to another profile or language', async () => {
  let reject
  const { state, props, locale } = await setup({ share: () => new Promise((resolve, fail) => { reject = fail }) })
  const pending = state.share()
  props.slug = 'another-profile'
  locale.value = 'en'
  await nextTick()
  reject(new Error('Old failure'))
  await pending
  assert.equal(state.open.value, false)
  assert.equal(state.url.value, 'https://wave.ba/en/kreatori/another-profile')
})
