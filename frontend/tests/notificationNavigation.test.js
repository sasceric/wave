import assert from 'node:assert/strict'
import { test } from 'node:test'
import { MessageChannel } from 'node:worker_threads'
import { notificationDestination, requestClientNavigation, startNotificationNavigation } from '../src/lib/notificationNavigation.js'

test('a resumed app routes the exact clicked conversation and acknowledges it', async () => {
  let handler
  let removed
  const paths = []
  const replies = []
  const serviceWorker = {
    addEventListener: (type, callback) => { handler = callback },
    removeEventListener: (type, callback) => { removed = callback },
  }
  const stop = startNotificationNavigation(async (path) => { paths.push(path) }, {
    serviceWorker, origin: 'https://wave.example.test',
  })
  await handler({
    data: { type: 'WAVE_OPEN_NOTIFICATION', url: 'https://wave.example.test/messages?conversation=42' },
    ports: [{ postMessage: (reply) => { replies.push(reply) } }],
  })
  assert.deepEqual(paths, ['/messages?conversation=42'])
  assert.deepEqual(replies, [{ type: 'WAVE_NOTIFICATION_OPENED' }])
  stop()
  assert.equal(removed, handler)
})

test('unrelated messages and unsafe targets cannot redirect the app', async () => {
  let handler
  const paths = []
  const replies = []
  startNotificationNavigation(async (path) => { paths.push(path) }, {
    serviceWorker: { addEventListener: (type, callback) => { handler = callback } },
    origin: 'https://wave.example.test',
  })
  await handler({ data: { type: 'WAVE_PUSH_RECEIVED' } })
  for (const url of ['https://other.example.test/messages?conversation=42', 'http://[']) {
    await handler({ data: { type: 'WAVE_OPEN_NOTIFICATION', url }, ports: [{ postMessage: (reply) => { replies.push(reply) } }] })
  }
  assert.equal(paths.length, 0)
  assert.equal(replies.length, 2)
  assert.ok(replies.every((reply) => reply.type === 'WAVE_NOTIFICATION_NAVIGATION_FAILED'))
})

test('an old app without the listener has one bounded timeout and falls back to native navigation', async () => {
  let requests = 0
  const opened = await requestClientNavigation({ postMessage: () => { requests += 1 } }, '/messages?conversation=42', {
    Channel: MessageChannel, timeoutMs: 5,
  })
  assert.equal(opened, false)
  assert.equal(requests, 1)
})

test('an interrupted route is not acknowledged as a successful notification opening', async () => {
  let handler
  const replies = []
  startNotificationNavigation(async () => false, {
    serviceWorker: { addEventListener: (type, callback) => { handler = callback } },
    origin: 'https://wave.example.test',
  })
  await handler({
    data: { type: 'WAVE_OPEN_NOTIFICATION', url: '/messages?conversation=42' },
    ports: [{ postMessage: (reply) => { replies.push(reply) } }],
  })
  assert.deepEqual(replies, [{ type: 'WAVE_NOTIFICATION_NAVIGATION_FAILED' }])
})

test('a closed client and a routing failure return control to native navigation', async () => {
  assert.equal(await requestClientNavigation({ postMessage: () => { throw new Error('closed') } }, '/messages', { Channel: MessageChannel }), false)
  assert.equal(await requestClientNavigation({
    postMessage: (message, ports) => ports[0].postMessage({ type: 'WAVE_NOTIFICATION_NAVIGATION_FAILED' }),
  }, '/messages', { Channel: MessageChannel }), false)
})

test('creator decisions open company pages and incoming events open creator pages', () => {
  for (const type of ['invitation_declined', 'offer_declined', 'offer_accepted', 'invitation_accepted', 'application_received']) {
    assert.equal(notificationDestination({ type }).name, 'account-campaigns')
  }
  assert.equal(notificationDestination({ type: 'creator_inquiry_rejected' }).name, 'account-inquiries')
  assert.equal(notificationDestination({ type: 'campaign_invitation' }).name, 'account-invitations')
  assert.equal(notificationDestination({ type: 'offer_received' }).name, 'account-offers')
  assert.equal(notificationDestination({ type: 'application_rejected' }).name, 'account-applications')
  assert.deepEqual(notificationDestination({ type: 'invitation_accepted', conversationId: 42 }), { name: 'messages', query: { conversation: 42 } })
})
