import assert from 'node:assert/strict'
import { test } from 'node:test'
import { areChatMessagesGrouped, chatDayKey, createChatTimeFormatter, groupChatMessages } from '../src/lib/chatTime.js'

process.env.TZ = 'Europe/Sarajevo'
const translate = (key) => ({
  'campaignChat.today': 'Today', 'campaignChat.yesterday': 'Yesterday', 'campaignChat.justNow': 'Just now',
})[key]
const formatter = createChatTimeFormatter('en', translate)

// Test calendar boundaries in the viewer's timezone, rather than the API's UTC date.
test('day dividers use local dates and keep messages in their original order', () => {
  const messages = [
    { id: 1, createdAt: '2026-10-04T21:59:00Z' },
    { id: 2, createdAt: '2026-10-04T22:01:00Z' },
    { id: 3, createdAt: '2026-10-05T17:00:00Z' },
  ]
  const days = groupChatMessages(messages)
  assert.equal(days.length, 2)
  assert.equal(days[0].dateKey, '2026-10-4')
  assert.equal(days[1].dateKey, '2026-10-5')
  assert.deepEqual(days.map((day) => day.messages.map((message) => message.id)), [[1], [2, 3]])
  const key = days[1].key
  assert.equal(groupChatMessages([...messages, { id: 4, createdAt: '2026-10-05T18:00:00Z' }])[1].key, key)
})

test('Today and Yesterday follow midnight, including daylight saving changes', () => {
  const now = Date.parse('2026-10-25T12:00:00+01:00')
  assert.equal(formatter.dayLabel('2026-10-25T02:00:00+02:00', now), 'Today')
  // Yesterday is more than 24 hours ago when summer time ends.
  assert.equal(formatter.dayLabel('2026-10-24T11:30:00+02:00', now), 'Yesterday')
  assert.match(formatter.dayLabel('2026-10-23T11:30:00+02:00', now), /23\.10\.2026/)
  const midnight = Date.parse('2026-10-06T00:01:00+02:00')
  assert.equal(formatter.dayLabel('2026-10-05T23:59:00+02:00', midnight), 'Yesterday')
})

test('relative timestamps progress through minutes, hours, days and longer periods', () => {
  const now = Date.parse('2026-10-05T19:00:00Z')
  const ago = (seconds) => formatter.ago(new Date(now - seconds * 1000).toISOString(), now)
  assert.equal(ago(59), 'Just now')
  assert.match(ago(60), /1 min.*ago/)
  assert.match(ago(5 * 60), /5 min.*ago/)
  assert.match(ago(3600), /1 hr.*ago/)
  assert.match(ago(86_400), /1 day.*ago/)
  assert.match(ago(7 * 86_400), /1 wk.*ago/)
  assert.match(ago(30 * 86_400), /1 mo.*ago/)
  assert.match(ago(365 * 86_400), /1 yr.*ago/)
  assert.equal(formatter.ago(new Date(now + 60_000).toISOString(), now), 'Just now')
})

test('messages show a 24-hour clock and retain the full date for accessible tooltips', () => {
  assert.equal(formatter.clock('2026-10-05T21:05:00Z'), '23:05')
  assert.equal(formatter.clock('2026-10-04T22:00:00Z'), '00:00')
  assert.equal(formatter.full('2026-10-05T21:05:00Z'), '05.10.2026 23:05')
})

test('all six supported locales have readable relative times and Latin date labels', () => {
  const now = Date.parse('2026-10-05T19:00:00Z')
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const localized = createChatTimeFormatter(locale, translate)
    assert.match(localized.ago('2026-10-05T18:55:00Z', now), /5/)
    assert.doesNotMatch(localized.dayLabel('2026-10-01T18:55:00Z', now), /[\u0400-\u04ff]/)
    assert.equal(localized.clock('2026-10-05T21:05:00Z'), '23:05')
  }
})

test('missing or invalid timestamps cannot break the inbox or drop a message', () => {
  for (const value of [null, undefined, '', 'invalid']) {
    assert.equal(formatter.ago(value, Date.now()), '')
    assert.equal(formatter.clock(value), '')
    assert.equal(formatter.full(value), '')
    assert.equal(formatter.dayLabel(value, Date.now()), '')
    assert.equal(chatDayKey(value), '')
  }
  assert.equal(groupChatMessages([{ id: 1, createdAt: 'invalid', body: 'keep this' }])[0].messages[0].body, 'keep this')
})

test('message runs split when sender, day or the five-minute window changes', () => {
  const previous = { senderId: 1, createdAt: '2026-10-05T18:00:00Z' }
  assert.equal(areChatMessagesGrouped(previous, { senderId: 1, createdAt: '2026-10-05T18:05:00Z' }), true)
  assert.equal(areChatMessagesGrouped(previous, { senderId: 2, createdAt: '2026-10-05T18:01:00Z' }), false)
  assert.equal(areChatMessagesGrouped(previous, { senderId: 1, createdAt: '2026-10-05T18:05:01Z' }), false)
  assert.equal(areChatMessagesGrouped({ senderId: 1, createdAt: '2026-10-05T21:59:00Z' }, { senderId: 1, createdAt: '2026-10-05T22:01:00Z' }), false)
  assert.equal(areChatMessagesGrouped(previous, { senderId: 1, createdAt: 'invalid' }), false)
  assert.equal(areChatMessagesGrouped(previous, { senderId: 1, createdAt: '2026-10-05T17:59:00Z' }), false)
  assert.equal(areChatMessagesGrouped({ senderRole: 'company', createdAt: previous.createdAt }, { senderRole: 'company', createdAt: '2026-10-05T18:01:00Z' }), true)
  assert.equal(areChatMessagesGrouped(null, previous), false)
})
