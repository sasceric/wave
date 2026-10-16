import assert from 'node:assert/strict'
import test from 'node:test'
import { rememberRegistrationIntent, registrationReturn } from '../src/lib/registrationIntent.js'

function memoryStorage() {
  const records = new Map()
  return {
    getItem: key => records.get(key) || null,
    setItem: (key, value) => records.set(key, value),
    removeItem: key => records.delete(key),
    records,
  }
}
const creator = { id: 42, accountType: 'creator', approved: true, emailVerified: true, profileComplete: true }

test('a campaign survives verification and approval, then resumes once', () => {
  const storage = memoryStorage()
  rememberRegistrationIntent('/kampanje/nova-kolekcija', 'creator', storage)
  assert.equal(registrationReturn(null, storage), null)
  for (const pending of [{ emailVerified: false }, { profileComplete: false }, { approved: false }]) {
    assert.equal(registrationReturn({ ...creator, ...pending }, storage), null)
    assert.equal(storage.records.size, 1)
  }
  assert.equal(registrationReturn(creator, storage), '/kampanje/nova-kolekcija')
  assert.equal(registrationReturn(creator, storage), null)
})

test('a creator profile can be resumed by its newly registered company', () => {
  const storage = memoryStorage()
  rememberRegistrationIntent('/kreatori/amina-kovac', 'company', storage)
  assert.equal(registrationReturn({ ...creator, accountType: 'company' }, storage), '/kreatori/amina-kovac')
})

test('another account cannot inherit a pending registration destination', () => {
  const storage = memoryStorage()
  rememberRegistrationIntent('/kampanje/nova-kolekcija', 'creator', storage)
  registrationReturn({ ...creator, approved: false }, storage)
  assert.equal(registrationReturn({ ...creator, id: 99 }, storage), null)
  assert.equal(storage.records.size, 0)
})

test('a different account role clears the old intention', () => {
  const storage = memoryStorage()
  rememberRegistrationIntent('/kampanje/nova-kolekcija', 'creator', storage)
  assert.equal(registrationReturn({ ...creator, accountType: 'company' }, storage), null)
  assert.equal(storage.records.size, 0)
})

test('external, account, malformed and expired destinations are rejected', () => {
  const storage = memoryStorage()
  for (const path of ['//evil.test', 'https://evil.test', '/racun', '/kampanje/test?next=//evil.test', '/kampanje/test/other', '/kampanje/test\\other']) {
    rememberRegistrationIntent(path, 'creator', storage)
    assert.equal(storage.records.size, 0)
  }
  rememberRegistrationIntent('/kampanje/validna', 'creator', storage)
  const [key, raw] = [...storage.records][0]
  storage.setItem(key, JSON.stringify({ ...JSON.parse(raw), createdAt: Date.now() - 15 * 86400000 }))
  assert.equal(registrationReturn(creator, storage), null)
  assert.equal(storage.records.size, 0)
})

test('unavailable browser storage does not break registration', () => {
  const storage = { getItem() { throw new Error('blocked') }, setItem() { throw new Error('blocked') } }
  assert.doesNotThrow(() => rememberRegistrationIntent('/kampanje/validna', 'creator', storage))
  assert.equal(registrationReturn(creator, storage), null)
})

for (const path of ['/racun/kampanje/nova', '/hr/racun/kampanje/nova', '/rs/nalog/kampanje/nova', '/me/nalog/kampanje/nova', '/si/racun/kampanje/nova', '/en/account/campaigns/new']) {
  test(`campaign creation resumes for companies only: ${path}`, () => {
    const storage = memoryStorage()
    rememberRegistrationIntent(path, 'creator', storage)
    assert.equal(storage.records.size, 0)
    rememberRegistrationIntent(path, 'company', storage)
    assert.equal(registrationReturn({ ...creator, accountType: 'company' }, storage), path)
  })
}
