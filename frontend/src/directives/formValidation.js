import i18n from '../i18n'
import { fieldValidationError } from '../lib/validationRules'

const states = new WeakMap()
let nextErrorId = 0
const selector = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]), select, textarea, [data-validation-field]'

function fields(form) {
  return Array.from(form.querySelectorAll(selector)).filter((field) => (
    field.closest('form') === form
    && (!field.closest('[data-validation-field]') || field.hasAttribute('data-validation-field'))
  ))
}

function descriptor(field, form) {
  const custom = field.hasAttribute('data-validation-field')
  let value = custom ? field.dataset.validationValue : field.value
  if (field.type === 'checkbox') value = field.checked ? 'checked' : ''
  if (field.type === 'radio') value = Array.from(form.elements).some((element) => element.type === 'radio' && element.name === field.name && element.checked) ? 'checked' : ''
  const rule = field.dataset.validationRule || field.type
  const equalField = field.dataset.equalTo ? form.querySelector(`[data-validation-key="${field.dataset.equalTo}"]`) : null
  return {
    value, type: rule,
    required: custom ? field.dataset.required === 'true' : field.required,
    disabled: custom ? field.dataset.disabled === 'true' : field.disabled || field.matches(':disabled'),
    minLength: custom ? Number(field.dataset.minlength || 0) : field.minLength,
    maxLength: custom ? Number(field.dataset.maxlength || 0) : field.maxLength,
    min: custom ? field.dataset.min : field.min,
    max: custom ? field.dataset.max : field.max,
    equalTo: equalField ? equalField.value : undefined,
    customError: field.validity?.customError ? field.validationMessage : '',
    typeMismatch: field.validity?.typeMismatch,
    patternMismatch: field.validity?.patternMismatch,
    stepMismatch: field.validity?.stepMismatch,
    badInput: field.validity?.badInput,
    phoneInvalid: rule === 'phone' && value?.trim() && field.dataset.phoneInvalid === 'true',
  }
}

function container(field) {
  return field.closest('.form-field') || field.closest('[data-validation-field]') || field.parentElement
}

function labelFor(field) {
  if (field.hasAttribute('data-validation-field')) {
    const parent = container(field)
    return parent.querySelector(':scope > label, :scope > span, :scope > .field-label')
  }
  const label = field.labels?.[0] || container(field).querySelector('.field-label')
  return label?.querySelector(':scope > span:not(.validation-required)') || label
}

function controlFor(field) {
  return field.hasAttribute('data-validation-field')
    ? field.querySelector('[data-validation-control]') || field.querySelector('.ql-editor') || field.querySelector('button, input') || field
    : field
}

function decorate(form) {
  const decoratedLabels = new Set()
  for (const field of fields(form)) {
    const legend = field.type === 'radio' ? field.closest('fieldset')?.querySelector(':scope > legend') : null
    const required = legend
      ? Array.from(form.elements).some(element => element.type === 'radio' && element.name === field.name && element.required)
      : descriptor(field, form).required
    const label = legend || labelFor(field)
    if (!label || decoratedLabels.has(label)) continue
    decoratedLabels.add(label)
    let star = label.querySelector(':scope > .validation-required')
    if (required && !star) {
      star = document.createElement('span')
      star.className = 'validation-required'
      star.textContent = ' *'
      star.setAttribute('aria-hidden', 'true')
      label.append(star)
    } else if (!required) star?.remove()
    const fieldName = label.textContent.replace(/\s*\*\s*$/, '').trim()
    if (fieldName && field.matches('textarea, input:not([type=checkbox]):not([type=radio]):not([type=file])')) {
      if (!field.hasAttribute('placeholder') || field.dataset.autoPlaceholder === 'true') {
        field.dataset.autoPlaceholder = 'true'
        field.setAttribute('placeholder', i18n.global.t('forms.enterField', { field: fieldName }))
      }
    }
    const editor = field.hasAttribute('data-validation-field') ? field.querySelector('.ql-editor') : null
    if (editor && fieldName && (!editor.getAttribute('data-placeholder') || editor.dataset.autoPlaceholder === 'true')) {
      editor.dataset.autoPlaceholder = 'true'
      editor.setAttribute('data-placeholder', i18n.global.t('forms.enterField', { field: fieldName }))
    }
  }
}

function renderError(field, result, state) {
  const control = controlFor(field)
  let record = state.errors.get(field)
  if (!result) {
    if (!record) return
    record.message.remove()
    control.classList.remove('is-field-invalid')
    control.removeAttribute('aria-invalid')
    const describedBy = (control.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && id !== record.message.id)
    if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '))
    else control.removeAttribute('aria-describedby')
    container(field).classList.remove('has-field-error')
    state.errors.delete(field)
    return
  }
  if (!record) {
    const message = document.createElement('small')
    message.id = `validation-error-${++nextErrorId}`
    message.className = 'validation-error'
    message.setAttribute('role', 'alert')
    container(field).append(message)
    record = { message }
    state.errors.set(field, record)
    control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '))
  }
  record.message.textContent = result.message || i18n.global.t(`validation.${result.key}`, result)
  control.classList.add('is-field-invalid')
  control.setAttribute('aria-invalid', 'true')
  container(field).classList.add('has-field-error')
}

function resetValidation(form) {
  const state = states.get(form)
  if (!state) return
  state.submitted = false
  for (const field of state.errors.keys()) renderError(field, null, state)
}

export function validateForm(form, focus = true) {
  const state = states.get(form)
  if (!state) return true
  decorate(form)
  for (const [field, record] of state.errors) {
    if (!form.contains(field)) {
      record.message.remove()
      state.errors.delete(field)
    }
  }
  let first = null
  for (const field of fields(form)) {
    const result = fieldValidationError(descriptor(field, form))
    renderError(field, result, state)
    if (result && !first) first = field
  }
  if (first && focus) controlFor(first).focus()
  return !first
}

export default {
  mounted(form) {
    const state = { errors: new Map(), submitted: false, originalNoValidate: form.noValidate }
    states.set(form, state)
    form.noValidate = true
    state.submit = (event) => {
      const valid = validateForm(form)
      // After a valid submit, reactive updates may clear fields for the next draft.
      state.submitted = !valid
      if (!valid) {
        event.preventDefault()
        event.stopImmediatePropagation()
      }
    }
    state.update = () => { if (state.submitted) validateForm(form, false) }
    state.reset = () => resetValidation(form)
    form.addEventListener('submit', state.submit, true)
    form.addEventListener('input', state.update)
    form.addEventListener('change', state.update)
    form.addEventListener('focusout', state.update)
    form.addEventListener('reset', state.reset)
    decorate(form)
  },
  updated(form) {
    decorate(form)
    if (states.get(form)?.submitted) validateForm(form, false)
  },
  unmounted(form) {
    const state = states.get(form)
    if (!state) return
    form.removeEventListener('submit', state.submit, true)
    form.removeEventListener('input', state.update)
    form.removeEventListener('change', state.update)
    form.removeEventListener('focusout', state.update)
    form.removeEventListener('reset', state.reset)
    form.noValidate = state.originalNoValidate
    states.delete(form)
  },
}
