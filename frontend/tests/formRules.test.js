import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { fieldValidationError } from '../src/lib/validationRules.js'
import { nationalPhoneNumber, formatInternationalPhoneNumber } from '../src/lib/phoneNumbers.js'
import { paginationItems } from '../src/lib/pagination.js'
import { parseDateOnly } from '../src/lib/datePicker.js'

test('required, contact length, optional and disabled fields validate without browser popups', () => {
  assert.equal(fieldValidationError({ value: '  ', required: true }).key, 'required')
  assert.equal(fieldValidationError({ value: 'Short message', minLength: 20 }).key, 'minLength')
  assert.equal(fieldValidationError({ value: 'A complete contact message.', minLength: 20 }), null)
  assert.equal(fieldValidationError({ value: '', type: 'date' }), null)
  assert.equal(fieldValidationError({ value: '', required: true, disabled: true }), null)
  assert.equal(fieldValidationError({ value: '', type: 'number', badInput: true }).key, 'number')
})

test('email, URL, number, date and confirmation rules reject malformed values', () => {
  for (const value of ['a', 'a@b', 'a b@example.com']) assert.equal(fieldValidationError({ value, type: 'email' }).key, 'email')
  assert.equal(fieldValidationError({ value: 'a@example.com', type: 'email' }), null)
  assert.equal(fieldValidationError({ value: 'javascript:alert(1)', type: 'url' }).key, 'url')
  assert.equal(fieldValidationError({ value: 'https://example.com', type: 'url' }), null)
  assert.equal(fieldValidationError({ value: '-1', type: 'number', min: 0 }).key, 'min')
  assert.equal(fieldValidationError({ value: '0', type: 'number', min: 0 }), null)
  assert.equal(fieldValidationError({ value: '2023-02-29', type: 'date' }).key, 'date')
  assert.ok(parseDateOnly('2024-02-29'))
  assert.equal(fieldValidationError({ value: '2099-01-01', type: 'date', max: '2026-10-07' }).key, 'dateMax')
  assert.equal(fieldValidationError({ value: 'password', equalTo: 'different' }).key, 'match')
})

test('phone display strips the selected calling code while storage remains international', () => {
  assert.equal(nationalPhoneNumber('+38761993212', 'BA'), '61993212')
  assert.equal(nationalPhoneNumber('+387 61 993 212', 'BA'), '61993212')
  assert.equal(nationalPhoneNumber('061993212', 'BA'), '061993212')
  assert.equal(formatInternationalPhoneNumber(nationalPhoneNumber('+38761993212', 'BA'), 'BA'), '+38761993212')
  assert.equal(nationalPhoneNumber('+447700900123', 'GB'), '7700900123')
  assert.equal(formatInternationalPhoneNumber('not a number', 'BA'), null)
})

test('pagination retains first and last three pages with gaps and the current neighborhood', () => {
  assert.deepEqual(paginationItems(1, 47).map((item) => item.page), [1, 2, 3, null, 45, 46, 47])
  assert.deepEqual(paginationItems(24, 47).map((item) => item.page), [1, 2, 3, null, 23, 24, 25, null, 45, 46, 47])
  assert.deepEqual(paginationItems(47, 47).map((item) => item.page), [1, 2, 3, null, 45, 46, 47])
  assert.deepEqual(paginationItems(1, 1).map((item) => item.page), [1])
  assert.deepEqual(paginationItems(2, 5).map((item) => item.page), [1, 2, 3, 4, 5])
})

test('all six locales contain real datepicker, birthday, form and validation labels', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    for (const key of ['placeholder', 'chooseDate', 'chooseMonth', 'chooseYear', 'nextMonth', 'previousMonth', 'clear', 'select']) assert.ok(catalog.datePicker[key], `${locale}: ${key}`)
    assert.ok(catalog.account.birthday)
    assert.ok(catalog.account.birthdayPrivate)
    assert.ok(catalog.forms.enterField)
    for (const key of ['required', 'email', 'minLength', 'maxLength', 'date', 'dateMax', 'phone', 'match']) assert.ok(catalog.validation[key], `${locale}: ${key}`)
  }
})


test('campaign filter and form labels resolve in every supported locale', async () => {
  const source = await readFile(new URL('../src/views/CampaignsView.vue', import.meta.url), 'utf8')
  const keys = [...source.matchAll(/\bt\(['"]([^'"]+)['"]/g)].map(match => match[1])
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url)))
    for (const key of [...keys, 'account.campaignDescriptionPlaceholder', 'account.deliverableItem', 'account.addDeliverable', 'account.deliverablePlaceholder']) {
      assert.equal(typeof key.split('.').reduce((value, part) => value?.[part], catalog), 'string', `${locale}: ${key}`)
    }
  }
})
