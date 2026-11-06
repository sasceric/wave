import assert from 'node:assert/strict'
import test from 'node:test'
import { forgetReferral, normalizeReferralCode, rememberReferral, storedReferral } from '../src/lib/referral.js'

function memoryStorage() {
  const values = new Map()
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)), removeItem: key => values.delete(key) }
}

test('referral codes from ?ref= are normalised and remembered for 30 days', () => {
  const storage = memoryStorage()
  assert.equal(normalizeReferralCode(' amar '), 'AMAR')
  assert.equal(normalizeReferralCode('a-b'), '')
  assert.equal(rememberReferral('<script>', storage, 0), '')
  assert.equal(storedReferral(storage, 0), '')
  assert.equal(rememberReferral('amar27', storage, 1000), 'AMAR27')
  assert.equal(storedReferral(storage, 1000 + 29 * 86400000), 'AMAR27')
  assert.equal(storedReferral(storage, 1000 + 31 * 86400000), '')
  forgetReferral(storage)
  assert.equal(storedReferral(storage, 1000), '')
})
