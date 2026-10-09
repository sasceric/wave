import assert from 'node:assert/strict'
import test from 'node:test'
import { setupView } from './setupView.js'
import { ticketSubmissionKey, validTicketFiles, ticketKinds, ticketCategories, ticketMaxBytes } from '../src/lib/supportTicket.js'

test('support attachment rules reject active content, oversized files and excessive count', () => {
  const png = { name: 'proof.png', size: 200, type: 'image/png' }
  assert.equal(validTicketFiles([png]), true)
  assert.equal(validTicketFiles([]), true)
  assert.equal(validTicketFiles([{ ...png, type: 'text/html' }]), false)
  assert.equal(validTicketFiles([{ ...png, size: ticketMaxBytes + 1 }]), false)
  assert.equal(validTicketFiles([png, png, png, png]), false)
  assert.equal(validTicketFiles([{ ...png, size: 0 }]), false)
  assert.match(ticketSubmissionKey(), /^[a-f0-9]{64}$/)
  assert.notEqual(ticketSubmissionKey(), ticketSubmissionKey())
})

test('ticket flow preserves details when moving back and sends a single multipart report with attachments', async () => {
  const calls = []
  let resolveSubmission
  const route = { meta: { routeName: 'support-create' }, hash: '' }
  const { state } = await setupView('../src/views/SupportView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async () => {} }) },
    '../lib/supportTicket': { ticketSubmissionKey, validTicketFiles, ticketKinds, ticketCategories },
    '../lib/api': { formatDate: value => value, apiRequest: async (path, options) => { calls.push({ path, options }); return new Promise(resolve => { resolveSubmission = resolve }) } },
    '../lib/phoneNumbers': { defaultPhoneCountry: () => 'BA', formatInternationalPhoneNumber: value => value },
  }, {}, { FormData, URLSearchParams })
  state.continueStep()
  assert.equal(state.step.value, 1)
  assert.equal(state.error.value, 'support.invalid')
  Object.assign(state.form, { name: 'Alex', email: 'alex@example.test', category: 'technical' })
  state.continueStep()
  assert.equal(state.step.value, 2)
  Object.assign(state.form, { title: 'Bug in profile', description: 'Here are the detailed steps to reproduce this bug.' })
  state.addFiles([new File(['%PDF-1.4\nTest'], 'proof.pdf', { type: 'application/pdf' })])
  state.continueStep()
  assert.equal(state.step.value, 3)
  await state.changeStep(2)
  assert.equal(state.form.title, 'Bug in profile')
  state.continueStep()
  const promise = state.send()
  await state.send()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].path, '/support/tickets')
  assert.equal(calls[0].options.body.get('email'), 'alex@example.test')
  assert.equal(calls[0].options.body.getAll('attachments[]').length, 1)
  resolveSubmission({ data: { number: 'T-0001', receiptSent: true, trackingUrl: 'https://wave.ba/podrska/tiket#token=' + 'a'.repeat(64) } })
  await promise
  assert.equal(state.successOpen.value, true)
  assert.equal(state.result.value.number, 'T-0001')
  await state.send()
  assert.equal(calls.length, 1)
  state.reset()
  assert.equal(state.result.value, null)
  assert.equal(state.files.value.length, 0)
})

test('failed sends preserve the submission key for retry and a tracking link never auto-selects another ticket', async () => {
  const keys = []
  const route = { meta: { routeName: 'support-create' }, hash: '' }
  const { state } = await setupView('../src/views/SupportView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: async () => {} }) },
    '../lib/supportTicket': { ticketSubmissionKey, validTicketFiles, ticketKinds, ticketCategories },
    '../lib/api': { formatDate: value => value, apiRequest: async (path, options) => { keys.push(options.body.get('submissionKey')); throw new Error('Disconnected') } },
    '../lib/phoneNumbers': { defaultPhoneCountry: () => 'BA', formatInternationalPhoneNumber: value => value },
  }, {}, { FormData })
  await state.send()
  await state.send()
  assert.equal(keys[0], keys[1])
  assert.equal(state.error.value, 'Disconnected')
  route.meta.routeName = 'support-track'
  await state.loadTracking()
  assert.equal(state.error.value, 'support.notFound')
  assert.equal(state.tracked.value, null)
})
