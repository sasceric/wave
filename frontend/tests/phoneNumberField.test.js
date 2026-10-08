import assert from 'node:assert/strict'
import test from 'node:test'
import { reactive } from 'vue'
import * as parser from 'libphonenumber-js/min'
import * as phoneNumbers from '../src/lib/phoneNumbers.js'
import { setupView } from './setupView.js'

test('the phone control keeps international validation when its parser is loaded with account forms', async () => {
  const props = reactive({ modelValue: '61 234567', countryCode: 'BA' })
  const { state, emitted } = await setupView('../src/components/shared/PhoneNumberField.vue', {
    'libphonenumber-js/min': parser,
    '../../lib/phoneNumbers': phoneNumbers,
  }, props)
  assert.equal(state.phoneInvalid.value, false)
  props.modelValue = 'abc'
  assert.equal(state.phoneInvalid.value, true)
  props.modelValue = ''
  assert.equal(state.phoneInvalid.value, false)
  state.updatePhone({ target: { value: '+385 91 2345678' } })
  assert.deepEqual(emitted[0], ['update:countryCode', 'HR'])
  assert.deepEqual(emitted[1], ['update:modelValue', '912345678'])
})
