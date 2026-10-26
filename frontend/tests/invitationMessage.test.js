import assert from 'node:assert/strict'
import test from 'node:test'
import { INVITATION_VARIABLES, defaultInvitationMessage, personaliseInvitation } from '../src/lib/invitationMessage.js'

const locales = ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']

test('every default invitation greets by name, tags all Wave profiles and signs with the company', () => {
  for (const locale of locales) {
    const message = defaultInvitationMessage(locale)
    assert.match(message, /^\S+ \{\{name\}\},\n/, locale)
    assert.match(message, /\n\{\{companyName\}\}$/, locale)
    for (const handle of ['@wave.ba.app', '@waveapp5', '@app.wave.ba']) assert.ok(message.includes(handle), `${locale} ${handle}`)
    assert.ok(message.length <= 2000, locale)
    const used = [...message.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((match) => match[1])
    assert.deepEqual([...new Set(used)].sort(), [...INVITATION_VARIABLES].sort(), locale)
  }
  assert.equal(defaultInvitationMessage('xx'), defaultInvitationMessage('bs'))
})

test('personalising matches the server, including spaces inside braces', () => {
  assert.equal(
    personaliseInvitation('Pozdrav {{name}},\n\nS poštovanjem,\n{{ companyName }}\n', { name: 'Lejla', companyName: 'Wave.ba' }),
    'Pozdrav Lejla,\n\nS poštovanjem,\nWave.ba',
  )
  assert.equal(personaliseInvitation('{{other}}', { name: 'A', companyName: 'B' }), '{{other}}')
})
