import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { baseCompile } from '@intlify/message-compiler'

function flatten(value, prefix = '', entries = {}) {
  if (value && typeof value === 'object') for (const [key, entry] of Object.entries(value)) flatten(entry, `${prefix}.${key}`, entries)
  else entries[prefix] = value
  return entries
}

test('German has every interface key, preserves interpolation and compiles as Vue I18n messages', async () => {
  const catalogs = await Promise.all(['en', 'de'].map(async locale => flatten(JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8')))))
  const [english, german] = catalogs
  assert.deepEqual(Object.keys(german), Object.keys(english))
  assert.ok(Object.keys(german).length > 2300)
  for (const [key, value] of Object.entries(german)) {
    assert.equal(typeof value, 'string', key)
    assert.ok(value.length, key)
    const placeholders = text => [...text.matchAll(/\{([^}]+)\}/g)].map(match => match[1]).sort()
    assert.deepEqual(placeholders(value), placeholders(english[key]), key)
    const errors = []
    baseCompile(value, { onError: error => errors.push(error.message) })
    assert.deepEqual(errors, [], key)
  }
  assert.equal(german['.app.home'], 'Startseite')
  assert.equal(german['.studio.contentLanguages.de'], 'Deutsch')
  assert.equal(german['.auth.passwordHint'], 'Mindestens 8 Zeichen.')
})
