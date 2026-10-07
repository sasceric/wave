import { parseDateOnly } from './datePicker.js'

export function fieldValidationError(field) {
  if (field.disabled) return null
  const value = String(field.value ?? '')
  const text = field.type === 'password' ? value : value.trim()
  if (field.required && !text) return { key: 'required' }
  if (field.customError) return { message: field.customError }
  if (field.badInput) return { key: field.type === 'number' ? 'number' : 'invalid' }
  if (!text) return null
  const length = Array.from(text).length
  if (field.minLength > 0 && length < field.minLength) return { key: 'minLength', count: field.minLength }
  if (field.maxLength > 0 && length > field.maxLength) return { key: 'maxLength', count: field.maxLength }
  if (field.type === 'email' && (field.typeMismatch || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text))) return { key: 'email' }
  if (field.type === 'url') {
    try {
      const url = new URL(text)
      if (!['http:', 'https:'].includes(url.protocol)) return { key: 'url' }
    } catch { return { key: 'url' } }
  }
  if (field.type === 'number') {
    if (!Number.isFinite(Number(value)) || field.badInput) return { key: 'number' }
    if (field.min !== '' && field.min != null && Number(value) < Number(field.min)) return { key: 'min', count: field.min }
    if (field.max !== '' && field.max != null && Number(value) > Number(field.max)) return { key: 'max', count: field.max }
    if (field.stepMismatch) return { key: 'step' }
  }
  if (field.type === 'date') {
    if (!parseDateOnly(text)) return { key: 'date' }
    if (field.min && text < field.min) return { key: 'dateMin', date: field.min }
    if (field.max && text > field.max) return { key: 'dateMax', date: field.max }
  }
  if (field.equalTo !== undefined && value !== field.equalTo) return { key: 'match' }
  if (field.patternMismatch) return { key: 'invalid' }
  if (field.phoneInvalid) return { key: 'phone' }
  return null
}
