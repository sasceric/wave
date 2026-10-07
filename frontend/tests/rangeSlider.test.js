import assert from 'node:assert/strict'
import test from 'node:test'
import { setupView } from './setupView.js'

const props = { modelValue: [0, 10000], min: 0, max: 30000, step: 100, label: 'Budget', fromLabel: 'From', toLabel: 'To', disabled: false, currency: '' }
test('both budget handles respect bounds and cannot cross', async () => {
  const { state, emitted } = await setupView('../src/components/shared/RangeSlider.vue', {}, { ...props })
  state.updateLower({ target: { value: '15000' } })
  assert.deepEqual(Array.from(emitted.at(-1)[1]), [10000, 10000])
  state.updateLower({ target: { value: '-100' } })
  assert.deepEqual(Array.from(emitted.at(-1)[1]), [0, 10000])
  state.updateUpper({ target: { value: '99999' } })
  assert.deepEqual(Array.from(emitted.at(-1)[1]), [0, 30000])
  state.updateUpper({ target: { value: '-100' } })
  assert.deepEqual(Array.from(emitted.at(-1)[1]), [0, 0])
})
test('disabled budget handles cannot apply a monetary filter', async () => {
  const { state, emitted } = await setupView('../src/components/shared/RangeSlider.vue', {}, { ...props, disabled: true })
  state.updateLower({ target: { value: '500' } })
  state.updateUpper({ target: { value: '20000' } })
  assert.equal(emitted.length, 0)
})
