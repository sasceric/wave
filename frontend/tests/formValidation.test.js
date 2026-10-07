import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { test } from 'node:test'
import { fieldValidationError } from '../src/lib/validationRules.js'

async function validationFixture({ custom = false, value = '', required = true } = {}) {
  const messages = []
  const listeners = new Map()
  const attributes = new Map([['aria-describedby', 'existing-hint']])
  const classes = new Set()
  let focused = 0
  const parent = {
    querySelector: () => null,
    append: message => messages.push(message),
    classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
  }
  const control = {
    classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
    getAttribute: name => attributes.get(name) ?? null,
    setAttribute: (name, value) => attributes.set(name, value),
    removeAttribute: name => attributes.delete(name),
    focus: () => { focused++ },
  }
  const field = {
    ...control,
    value, type: 'text', required, disabled: false,
    minLength: -1, maxLength: -1, dataset: { required: String(required), validationValue: value },
    hasAttribute: name => custom && name === 'data-validation-field',
    querySelector: () => control,
    closest: selector => selector === 'form' ? form : selector === '.form-field' ? parent : null,
    matches: () => false,
    parentElement: parent,
  }
  const form = {
    noValidate: false,
    elements: [field],
    querySelectorAll: () => [field],
    contains: candidate => candidate === field,
    addEventListener: (name, listener) => listeners.set(name, listener),
    removeEventListener: (name, listener) => { if (listeners.get(name) === listener) listeners.delete(name) },
  }
  const document = {
    createElement: () => {
      const message = {
        setAttribute: () => {},
        remove: () => { const index = messages.indexOf(message); if (index >= 0) messages.splice(index, 1) },
      }
      return message
    },
  }
  const context = vm.createContext({ document })
  const source = await readFile(new URL('../src/directives/formValidation.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  const modules = {
    '../i18n': { default: { global: { t: key => key } } },
    '../lib/phoneNumbers': { formatInternationalPhoneNumber: () => '' },
    '../lib/validationRules': { fieldValidationError },
  }
  await module.link(specifier => new vm.SyntheticModule(Object.keys(modules[specifier]), function () {
    for (const [name, exported] of Object.entries(modules[specifier])) this.setExport(name, exported)
  }, { context }))
  await module.evaluate()
  const directive = module.namespace.default
  directive.mounted(form)
  return {
    directive, form, field, messages, classes, attributes, listeners,
    focused: () => focused,
    setValue: value => { field.value = value; field.dataset.validationValue = value },
    submit: () => {
      const result = { prevented: false, stopped: false }
      listeners.get('submit')({
        preventDefault: () => { result.prevented = true },
        stopImmediatePropagation: () => { result.stopped = true },
      })
      return result
    },
  }
}

for (const custom of [false, true]) {
  const kind = custom ? 'custom editor/select' : 'native input'
  test(`${kind}: a successful submission can clear the draft without showing required errors`, async () => {
    const ui = await validationFixture({ custom, value: 'A valid message' })
    assert.equal(ui.submit().prevented, false)
    ui.setValue('')
    ui.directive.updated(ui.form)
    for (const name of ['input', 'change', 'focusout']) ui.listeners.get(name)()
    assert.equal(ui.messages.length, 0)
    assert.equal(ui.attributes.has('aria-invalid'), false)
    assert.equal(ui.focused(), 0)
    assert.equal(ui.submit().prevented, true)
    assert.equal(ui.messages.length, 1)
  })

  test(`${kind}: invalid drafts stay blocked, and corrected submissions clear previous errors`, async () => {
    const ui = await validationFixture({ custom })
    assert.deepEqual(ui.submit(), { prevented: true, stopped: true })
    assert.equal(ui.messages[0].textContent, 'validation.required')
    assert.equal(ui.attributes.get('aria-invalid'), 'true')
    ui.setValue('Corrected message')
    ui.listeners.get('input')()
    assert.equal(ui.messages.length, 0)
    assert.equal(ui.attributes.get('aria-describedby'), 'existing-hint')
    assert.equal(ui.submit().prevented, false)
    ui.setValue('')
    ui.directive.updated(ui.form)
    assert.equal(ui.messages.length, 0)
    assert.equal(ui.classes.has('has-field-error'), false)
  })
}

test('reset clears errors, preserves accessibility hints, and requires validation on the next submit', async () => {
  const ui = await validationFixture()
  ui.submit()
  assert.equal(ui.messages.length, 1)
  ui.listeners.get('reset')()
  ui.directive.updated(ui.form)
  assert.equal(ui.messages.length, 0)
  assert.equal(ui.attributes.get('aria-describedby'), 'existing-hint')
  assert.equal(ui.attributes.has('aria-invalid'), false)
  assert.equal(ui.submit().prevented, true)
  ui.directive.unmounted(ui.form)
  assert.equal(ui.listeners.size, 0)
  assert.equal(ui.form.noValidate, false)
})
