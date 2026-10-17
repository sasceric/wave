import assert from 'node:assert/strict'
import { test } from 'node:test'
import { setupView } from './setupView.js'

async function setupImage({ uploadError = null, deleteStatus = null } = {}) {
  const requests = []
  const revoked = []
  let picked = 0
  const { state } = await setupView('../src/components/shared/ProfileImageField.vue', {
    '../../lib/api': {
      apiUpload: async (path, file) => {
        requests.push({ method: 'POST', path, file })
        if (uploadError) throw new Error(uploadError)
        return { data: { id: 22, url: '/new-image.webp' } }
      },
      apiRequest: async (path, options) => {
        requests.push({ path, ...options })
        if (deleteStatus) throw Object.assign(new Error('Cleanup failed'), { status: deleteStatus })
      },
    },
  }, { modelValue: 11, previewUrl: '/old-image.webp', folder: 'company-cover' }, {
    URL: { createObjectURL: () => 'blob:draft', revokeObjectURL: url => revoked.push(url) },
  })
  state.fileInput.value = { click: () => { picked++ } }
  return { state, requests, revoked, picked: () => picked }
}

function select(state) {
  const event = { target: { files: [{ name: 'cover.png' }], value: 'cover.png' } }
  state.selectFile(event)
  assert.equal(event.target.value, '')
}

test('existing images require removal before opening the upload picker; removal alone makes no request', async () => {
  const { state, requests, picked } = await setupImage()
  state.openFilePicker()
  assert.equal(picked(), 0)
  assert.equal(state.preview.value, '/old-image.webp')
  state.removalConfirmationOpen.value = true
  state.stageRemoval()
  assert.equal(state.removalConfirmationOpen.value, false)
  assert.equal(state.preview.value, '')
  state.openFilePicker()
  assert.equal(picked(), 1)
  const change = await state.prepareSave()
  assert.equal(change.mediaId, null)
  assert.equal(change.previousMediaId, 11)
  assert.equal(requests.length, 0)
})

test('remove then replace prepares one upload without deleting the saved image and commits the draft', async () => {
  const { state, requests, revoked } = await setupImage()
  state.stageRemoval()
  select(state)
  assert.equal(state.preview.value, 'blob:draft')
  const change = await state.prepareSave()
  assert.equal(change.previousMediaId, 11)
  assert.equal(change.mediaId, 22)
  assert.equal(await state.prepareSave(), change)
  assert.equal(requests.length, 1)
  assert.equal(requests[0].method, 'POST')
  state.commit()
  assert.equal(state.pendingFile.value, null)
  assert.equal(state.pendingRemoval.value, false)
  assert.equal(state.hasPreparedChange.value, false)
  assert.deepEqual(revoked, ['blob:draft'])
})

test('failed profile saves clean up only the new upload and allow another attempt', async () => {
  const { state, requests } = await setupImage()
  state.stageRemoval()
  select(state)
  const change = await state.prepareSave()
  await state.rollback(change)
  assert.equal(requests[1].path, '/media/22')
  assert.equal(requests[1].method, 'DELETE')
  assert.equal(state.hasPreparedChange.value, false)
  assert.equal(state.preview.value, 'blob:draft')
  await state.prepareSave()
  assert.equal(requests[2].method, 'POST')
  assert.ok(requests.every(request => request.path !== '/media/11'))
})

test('failed uploads leave the saved image untouched and the draft retryable', async () => {
  const { state, requests } = await setupImage({ uploadError: 'Invalid image' })
  state.stageRemoval()
  select(state)
  await assert.rejects(state.prepareSave(), /Invalid image/)
  assert.equal(state.hasPreparedChange.value, false)
  assert.equal(state.preview.value, 'blob:draft')
  assert.equal(requests.length, 1)
})

test('rollback accepts an already removed upload but does not hide permission failures', async () => {
  for (const status of [404, 403]) {
    const { state } = await setupImage({ deleteStatus: status })
    state.stageRemoval()
    select(state)
    const change = await state.prepareSave()
    if (status === 404) {
      await state.rollback(change)
      assert.equal(state.hasPreparedChange.value, false)
    } else {
      await assert.rejects(state.rollback(change), error => error.status === 403)
    }
  }
})
