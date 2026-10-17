import assert from 'node:assert/strict'
import test from 'node:test'
import { isProviderInThread } from '../src/lib/collaborationRole.js'

test('the same agency uses its actual side in each collaboration', () => {
  const agency = { id: 7, accountType: 'company' }
  assert.equal(isProviderInThread({ viewerRole: 'provider' }, agency), true)
  assert.equal(isProviderInThread({ viewerRole: 'client' }, agency), false)
  assert.equal(isProviderInThread({ creator: { ownerId: 7 } }, agency), true)
  assert.equal(isProviderInThread({ creator: { ownerId: 9 } }, agency), false)
})

test('legacy creator and company rows keep their established role', () => {
  assert.equal(isProviderInThread({}, { accountType: 'creator' }), true)
  assert.equal(isProviderInThread({}, { accountType: 'company' }), false)
  assert.equal(isProviderInThread(null, { accountType: 'creator' }), false)
  assert.equal(isProviderInThread({ viewerRole: 'provider' }, null), false)
})
