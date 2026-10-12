import assert from 'node:assert/strict'
import test from 'node:test'
import { ref } from 'vue'
import { setupView } from './setupView.js'

const redirect = { id: 1, sourcePath: '/kreatori/old-profile', targetPath: '/kreatori', enabled: true, preserveQuery: false, origin: 'deleted-creator' }

test('redirect editor creates and edits structured rules and keeps a failed draft open', async () => {
  const requests = []
  let fail = false
  const { state } = await setupView('../src/views/AdminRedirectsView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ isAdmin: true }) },
    '../lib/api': { formatDate: value => value, apiGet: async () => ({ data: [redirect], meta: { total: 1, page: 1 } }), apiRequest: async (path, options) => { requests.push({ path, options }); if (fail) throw new Error('Invalid rule'); return { data: redirect } } },
  })
  let open = false
  state.editor.value = { showModal: () => { open = true }, close: () => { open = false } }
  state.edit()
  state.sourcePath.value = '/old'
  state.targetPath.value = '/kreatori'
  await state.save()
  assert.equal(requests[0].path, '/admin/redirects')
  assert.equal(requests[0].options.method, 'POST')
  assert.equal(requests[0].options.body.preserveQuery, false)
  assert.equal(open, false)
  state.edit(redirect)
  state.targetPath.value = '/kompanije'
  fail = true
  await state.save()
  assert.equal(requests[1].options.method, 'PUT')
  assert.equal(state.targetPath.value, '/kompanije')
  assert.equal(open, true)
  assert.ok(state.formError.value)
})

test('redirect actions disable a rule and delete only the selected record', async () => {
  const requests = []
  const { state } = await setupView('../src/views/AdminRedirectsView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ isAdmin: true }) },
    '../lib/api': { formatDate: value => value, apiGet: async () => ({ data: [], meta: { total: 0, page: 1 } }), apiRequest: async (path, options) => { requests.push({ path, options }); return {} } },
  })
  await state.toggle(redirect)
  assert.equal(requests[0].options.body.enabled, false)
  assert.equal(requests[0].options.body.sourcePath, redirect.sourcePath)
  assert.equal(requests[0].options.method, 'PUT')
  state.requestDelete([redirect.id])
  await state.remove()
  assert.equal(requests[1].path, '/admin/redirects/bulk-delete')
  assert.deepEqual(Array.from(requests[1].options.body.ids), [1])
  assert.equal(requests[1].options.method, 'DELETE')
  assert.equal(state.deleting.value, null)
})


test('redirects use the campaign table sorting and bulk delete waits for confirmation', async () => {
  const requests = []
  const reads = []
  let fail = false
  const { state } = await setupView('../src/views/AdminRedirectsView.vue', {
    '../composables/useCurrentUser': { currentUser: ref({ isAdmin: true }) },
    '../lib/api': {
      formatDate: value => value,
      apiGet: async path => { reads.push(path); return { data: [], meta: { total: 0, page: 1 } } },
      apiRequest: async (path, options) => { requests.push({ path, options }); if (fail) throw new Error('Failed'); return {} },
    },
  })
  await state.changeList({ page: 2, limit: 50, sort: 'targetPath', direction: 'desc' })
  const query = new URL(reads[0], 'http://localhost').searchParams
  assert.equal(query.get('page'), '2')
  assert.equal(query.get('limit'), '50')
  assert.equal(query.get('sort'), 'targetPath')
  assert.equal(query.get('direction'), 'desc')
  state.requestDelete([1, 2])
  assert.equal(requests.length, 0)
  fail = true
  await state.remove()
  assert.ok(state.deleting.value)
  assert.equal(state.error.value, 'Failed')
  fail = false
  await state.remove()
  assert.deepEqual(Array.from(requests[1].options.body.ids), [1, 2])
  assert.equal(state.deleting.value, null)
})
