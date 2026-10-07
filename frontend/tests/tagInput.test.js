import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function setupTags(overrides = {}) {
  const props = vue.reactive({
    modelValue: ['Travel'], maxTags: 10, maxLength: 40, disabled: false,
    limitLabel: 'Maximum 10 tags', tooLongLabel: 'Maximum 40 characters',
    ...overrides,
  })
  const view = await setupView('../src/components/shared/TagInput.vue', {
    vue: { ...vue, useId: () => 'test' },
  }, props)
  return { ...view, props }
}

test('Enter creates a trimmed tag without submitting the profile form', async () => {
  const { state, emitted, props } = await setupTags()
  let prevented = false
  state.draft.value = '  Nature  '
  state.handleKeydown({ key: 'Enter', preventDefault: () => { prevented = true } })
  assert.equal(prevented, true)
  assert.deepEqual(Array.from(emitted[0][1]), ['Travel', 'Nature'])
  assert.deepEqual(Array.from(props.modelValue), ['Travel'])
  assert.equal(state.draft.value, '')
})

test('empty and duplicate tags do not add chips, while blur commits a pending tag', async () => {
  const { state, emitted } = await setupTags()
  for (const text of ['   ', ' travel ']) {
    state.draft.value = text
    state.commitTag()
  }
  assert.equal(emitted.length, 0)
  state.draft.value = 'Food, drinks'
  state.commitTag()
  assert.deepEqual(Array.from(emitted[0][1]), ['Travel', 'Food, drinks'])
})

test('removing a tag emits a new array and returns focus without scrolling', async () => {
  const { state, emitted } = await setupTags({ modelValue: ['Travel', 'Nature'] })
  let options
  state.input.value = { focus: (value) => { options = value }, setCustomValidity: () => {} }
  state.removeTag(0)
  await vue.nextTick()
  assert.deepEqual(Array.from(emitted[0][1]), ['Nature'])
  assert.equal(options.preventScroll, true)
})

test('IME confirmation does not create a partial tag or consume Enter', async () => {
  const { state, emitted } = await setupTags()
  state.draft.value = 'Nature'
  for (const event of [{ isComposing: true }, { keyCode: 229 }]) {
    state.handleKeydown({ key: 'Enter', ...event, preventDefault: () => assert.fail('Consumed IME Enter') })
  }
  state.composing.value = true
  state.commitTag()
  assert.equal(emitted.length, 0)
  assert.equal(state.draft.value, 'Nature')
})

test('tag limits retain invalid drafts and clearing an error restores form validity', async () => {
  const { state, emitted, props } = await setupTags({ maxTags: 1 })
  const validity = []
  state.input.value = { setCustomValidity: (value) => { validity.push(value) } }
  state.draft.value = 'Nature'
  state.commitTag()
  assert.equal(state.error.value, props.limitLabel)
  assert.equal(state.draft.value, 'Nature')
  assert.equal(emitted.length, 0)
  props.maxTags = 10
  state.draft.value = 'x'.repeat(41)
  state.commitTag()
  assert.equal(state.error.value, props.tooLongLabel)
  state.draft.value = 'Travel'
  state.commitTag()
  assert.equal(validity.at(-1), '')
  assert.equal(state.error.value, '')
})

test('disabled inputs cannot add or remove tags', async () => {
  const { state, emitted } = await setupTags({ disabled: true })
  state.draft.value = 'Nature'
  state.commitTag()
  state.removeTag(0)
  assert.equal(emitted.length, 0)
})
