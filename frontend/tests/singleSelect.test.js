import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

const options = [
  { value: '', label: 'All' },
  { value: 'unavailable', label: 'Unavailable', disabled: true },
  { value: 'verified', label: 'Verified' },
]
const modules = { vue: { ...vue, useId: () => 'test', onMounted: () => {}, onBeforeUnmount: () => {} } }

test('single select opens on the current option and emits one value before returning focus', async () => {
  const focused = []
  const focusOptions = []
  const document = { getElementById: (id) => ({ focus: (options) => { focused.push(id); focusOptions.push(options) } }) }
  const { state, emitted } = await setupView('../src/components/shared/SingleSelect.vue', modules, { modelValue: '', options }, { document })
  state.trigger.value = { focus: (options) => { focused.push('trigger'); focusOptions.push(options) } }
  await state.openMenu()
  assert.equal(state.isOpen.value, true)
  assert.equal(focused.at(-1), 'single-select-test-option-0')
  state.moveOption(0, 1)
  assert.equal(focused.at(-1), 'single-select-test-option-2')
  state.selectOption(options[2])
  await vue.nextTick()
  assert.deepEqual(emitted, [['update:modelValue', 'verified']])
  assert.equal(state.isOpen.value, false)
  assert.equal(focused.at(-1), 'trigger')
  assert.ok(focusOptions.every((options) => options.preventScroll === true))
})

test('disabled dropdowns and options cannot change selection', async () => {
  const { state, emitted } = await setupView('../src/components/shared/SingleSelect.vue', modules, { modelValue: '', options, disabled: true })
  await state.openMenu()
  state.selectOption(options[2])
  assert.equal(state.isOpen.value, false)
  assert.equal(emitted.length, 0)
  const enabled = await setupView('../src/components/shared/SingleSelect.vue', modules, { modelValue: '', options })
  enabled.state.selectOption(options[1])
  assert.equal(enabled.emitted.length, 0)
})

test('Tab focus leaving the dropdown closes it without stealing focus back', async () => {
  const { state } = await setupView('../src/components/shared/SingleSelect.vue', modules, { modelValue: '', options })
  const internal = {}
  state.root.value = { contains: (target) => target === internal }
  state.isOpen.value = true
  state.closeOnFocusOut({ relatedTarget: internal })
  assert.equal(state.isOpen.value, true)
  state.closeOnFocusOut({ relatedTarget: null })
  assert.equal(state.isOpen.value, false)
})
