import assert from 'node:assert/strict'
import test from 'node:test'
import { plainProfileName } from '../src/lib/plainProfileName.js'

test('profile names lose pasted font styles while keeping real accents and scripts', () => {
  assert.equal(plainProfileName('𝐀𝐦𝐢𝐥𝐚 𝐇𝐚𝐦𝐳𝐢𝐜'), 'Amila Hamzic')
  assert.equal(plainProfileName('𝘈𝘮𝘪𝘭𝘢 Čačić'), 'Amila Čačić')
  assert.equal(plainProfileName(' Šehić Đurić Željko '), 'Šehić Đurić Željko')
  assert.equal(plainProfileName('E\u0301milie 李小龍 Амина'), 'Émilie 李小龍 Амина')
})
