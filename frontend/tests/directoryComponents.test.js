import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

test('shared mobile filters lock scrolling, close on Escape, and restore focus', async () => {
  let returnedFocus = false
  let panelFocused = false
  const document = { body: { style: { overflow: 'auto' } }, activeElement: { focus: () => { returnedFocus = true } } }
  const props = reactive({ modelValue: false })
  const { state, emitted } = await setupView('../src/components/shared/DirectoryFilterPanel.vue', {}, props, { document })
  state.filterPanel.value = { focus: () => { panelFocused = true } }
  props.modelValue = true
  await nextTick()
  await new Promise(setImmediate)
  assert.equal(document.body.style.overflow, 'hidden')
  assert.equal(panelFocused, true)
  state.trapFocus({ key: 'Escape', preventDefault: () => {} })
  assert.deepEqual(emitted, [['update:modelValue', false]])
  props.modelValue = false
  await nextTick()
  assert.equal(document.body.style.overflow, 'auto')
  assert.equal(returnedFocus, true)
})

test('shared sticky card activates at the header offset and clears above it', async () => {
  const window = { getComputedStyle: () => ({ top: '62px' }) }
  const { state } = await setupView('../src/components/shared/DirectoryToolbar.vue', {}, {}, { window })
  state.toolbar.value = {}
  let top = 220
  state.toolbarAnchor.value = { getBoundingClientRect: () => ({ top }) }
  state.updateStickyState()
  assert.equal(state.toolbarSticky.value, false)
  top = 62
  state.updateStickyState()
  assert.equal(state.toolbarSticky.value, true)
  top = 140
  state.updateStickyState()
  assert.equal(state.toolbarSticky.value, false)
})
