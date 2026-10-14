import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import test from 'node:test'
import { nextTick, reactive, ref } from 'vue'
import { setupView } from './setupView.js'

// Missing labels previously leaked raw studio.* keys into the work/package editor.
test('all literal studio labels used by the frontend exist in every locale', async () => {
  async function sources(directory) {
    const entries = await readdir(directory, { withFileTypes: true })
    const nested = await Promise.all(entries.map(entry => {
      const url = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory)
      return entry.isDirectory() ? sources(url) : /\.(vue|js)$/.test(entry.name) ? readFile(url, 'utf8') : ''
    }))
    return nested.flat()
  }
  const keys = new Set((await sources(new URL('../src/', import.meta.url)))
    .flatMap(source => [...source.matchAll(/\bt\(['"]studio\.([\w]+)['"]/g)].map(match => match[1])))
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    for (const key of keys) assert.equal(typeof catalog.studio[key], 'string', `${locale}: studio.${key}`)
  }
})

test('presentation defaults to two public works and excludes withdrawn public permission', async () => {
  const profile = reactive({ id: 9, portfolio: [
    { id: 'first', publicPermission: true },
    { id: 'private', publicPermission: false },
    { id: 'legacy' },
    { id: 'third', publicPermission: true },
  ] })
  const { state } = await setupView('../src/components/creators/CreatorMediaKit.vue', {
    '../../composables/useCurrentUser': { currentUser: ref(null) },
  }, { profile })
  assert.deepEqual([...state.selectedIds.value], ['first', 'legacy'])
  state.selectedIds.value.push('private')
  assert.deepEqual([...state.selectedWorks.value.map(work => work.id)], ['first', 'legacy'])
  profile.portfolio[0].publicPermission = false
  await nextTick()
  assert.deepEqual([...state.selectedWorks.value.map(work => work.id)], ['legacy'])
  assert.equal(state.selectedIds.value.includes('first'), false)
  state.previewOpen.value = true
  profile.id = 10
  profile.portfolio = [{ id: 'new-owner-work', publicPermission: true }]
  await nextTick()
  assert.equal(state.previewOpen.value, false)
  assert.deepEqual([...state.selectedIds.value], ['new-owner-work'])
})
