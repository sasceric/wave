import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { ref } from 'vue'
import { setupView } from './setupView.js'
import { qrImages } from '../src/lib/qrImage.js'

test('QR generation creates local PNG and SVG images for a permanent Wave URL', async () => {
  const image = await qrImages('https://wave.ba/q/0123456789abcdef0123456789abcdef')
  assert.ok(image.png.startsWith('data:image/png;base64,'))
  assert.ok(image.svg.startsWith('<svg'))
  assert.ok(image.svg.includes('viewBox'))
})

test('QR admin create and edit send structured API data, preserve stable URL, and open preview', async () => {
  const requests = []
  const link = { id: 1, label: 'Poster', destination: 'https://wave.ba/kampanje', url: 'https://wave.ba/q/0123456789abcdef0123456789abcdef' }
  const { state } = await setupView('../src/views/AdminQrView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ id: 1, isAdmin: true }) },
    '../lib/api': {
      formatDate: (value) => value,
      apiGet: async () => ({ data: [link], meta: { page: 1, total: 1 } }),
      apiRequest: async (path, options) => { requests.push({ path, options }); return { data: { ...link, ...options.body } } },
    },
    '../lib/qrImage': { qrImages: async (url) => ({ png: `local:${url}`, svg: '<svg />' }) },
  })
  let opened = 0
  let editorOpened = false
  state.dialog.value = { showModal: () => { opened++ } }
  state.editor.value = { showModal: () => { editorOpened = true }, close: () => { editorOpened = false } }
  state.create()
  assert.equal(editorOpened, true)
  state.label.value = link.label
  state.destination.value = link.destination
  await state.save()
  assert.equal(requests[0].path, '/admin/qr-links')
  assert.equal(requests[0].options.method, 'POST')
  assert.equal(requests[0].options.body.label, 'Poster')
  assert.equal(state.selected.value.url, link.url)
  assert.equal(opened, 1)
  assert.equal(editorOpened, false)
  state.edit(link)
  assert.equal(editorOpened, true)
  state.label.value = 'New poster'
  state.destination.value = 'https://wave.ba/kreatori'
  await state.save()
  assert.equal(requests[1].path, '/admin/qr-links/1')
  assert.equal(requests[1].options.method, 'PUT')
  assert.equal(state.selected.value.url, link.url)
  assert.equal(state.editId.value, null)
  assert.equal(editorOpened, false)
})

test('QR form stays open with its draft and error when saving fails', async () => {
  const { state } = await setupView('../src/views/AdminQrView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ id: 1, isAdmin: true }) },
    '../lib/api': {
      formatDate: (value) => value,
      apiGet: async () => ({ data: [], meta: { page: 1, total: 0 } }),
      apiRequest: async () => { throw new Error('Destination is not valid') },
    },
  })
  let editorOpened = false
  state.editor.value = { showModal: () => { editorOpened = true }, close: () => { editorOpened = false } }
  state.create()
  state.label.value = 'Poster'
  state.destination.value = 'https://example.com'
  await state.save()
  assert.equal(editorOpened, true)
  assert.equal(state.formError.value, 'Destination is not valid')
  assert.equal(state.label.value, 'Poster')
  assert.equal(state.destination.value, 'https://example.com')
  assert.equal(state.saving.value, false)
  state.dismissEditor()
  assert.equal(editorOpened, false)
  state.create()
  assert.equal(state.formError.value, '')
  assert.equal(state.label.value, '')
})

test('QR admin page and copy exist in all six languages', async () => {
  const routes = JSON.parse(await readFile(new URL('../../config/localized_routes.json', import.meta.url)))
  let keys
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    keys ??= Object.keys(catalog.adminQr).sort()
    assert.deepEqual(Object.keys(catalog.adminQr).sort(), keys)
    assert.equal(routes[locale]['admin-qr'], 'admin/qr')
    assert.ok(catalog.home.findCreator)
    assert.ok(catalog.app.tiktok)
  }
})

test('QR statistics load the selected code and show failures without stale data', async () => {
  let fail = false
  const paths = []
  const summary = { scans: 8, lastScanAt: '2026-10-08T12:00:00Z', locations: [{ country: 'BA', city: 'Sarajevo', scans: 8 }], daily: [{ day: '2026-10-08', scans: 8 }] }
  const { state } = await setupView('../src/views/AdminQrView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ isAdmin: true }) },
    '../lib/api': { formatDate: (value) => value, apiGet: async (path) => { paths.push(path); if (fail) throw new Error('Statistics unavailable'); return { data: summary } }, apiRequest: async () => ({}) },
  })
  state.statisticsDialog.value = { showModal() {} }
  await state.showStatistics({ id: 7, label: 'Poster' })
  assert.equal(paths[0], '/admin/qr-links/7/statistics')
  assert.equal(state.statistics.value.scans, 8)
  assert.equal(state.statisticsLoading.value, false)
  fail = true
  await state.showStatistics({ id: 8, label: 'Flyer' })
  assert.equal(state.statistics.value, null)
  assert.equal(state.statisticsError.value, 'Statistics unavailable')
  assert.equal(state.statisticsLoading.value, false)
})
