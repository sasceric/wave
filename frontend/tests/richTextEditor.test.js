import assert from 'node:assert/strict'
import { test } from 'node:test'
import { setupView } from './setupView.js'

test('initial toolbar synchronization never focuses an unselected rich text editor', async () => {
  const { state } = await setupView('../src/components/shared/RichTextEditor.vue', {}, { label: 'About' })
  state.editorReady.value = true
  state.editor.value = {
    getQuill: () => ({
      getSelection: () => null,
      getFormat: () => { throw new Error('getFormat without a range would focus the editor') },
    }),
  }
  state.syncToolbar()
  assert.equal(Object.keys(state.currentFormats.value).length, 0)
})

test('toolbar formats still reflect an explicitly selected range', async () => {
  const { state } = await setupView('../src/components/shared/RichTextEditor.vue', {}, { label: 'About' })
  const range = { index: 2, length: 3 }
  state.editorReady.value = true
  state.editor.value = {
    getQuill: () => ({
      getSelection: () => range,
      getFormat: (selected) => {
        assert.equal(selected, range)
        return { bold: true }
      },
    }),
  }
  state.syncToolbar()
  assert.equal(state.currentFormats.value.bold, true)
})
