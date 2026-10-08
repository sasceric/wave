import assert from 'node:assert/strict'
import { test } from 'node:test'
import { groupInboxThreads } from '../src/lib/inboxGroups.js'

test('recent chats use local calendar dates and preserve server order within date groups', () => {
  const now = new Date(2026, 9, 8, 12)
  const threads = [
    { id: 1, lastMessageAt: new Date(2026, 9, 8, 11).toISOString() },
    { id: 2, lastMessageAt: new Date(2026, 9, 8, 0, 1).toISOString() },
    { id: 3, lastMessageAt: new Date(2026, 9, 7, 23, 59).toISOString() },
    { id: 4, lastMessageAt: new Date(2026, 9, 5, 0).toISOString() },
    { id: 5, lastMessageAt: new Date(2026, 9, 4, 23, 59).toISOString() },
  ]
  assert.deepEqual(groupInboxThreads(threads, now).map(group => [group.key, group.threads.map(thread => thread.id)]), [
    ['today', [1, 2]], ['yesterday', [3]], ['thisWeek', [4]], ['earlier', [5]],
  ])
})

test('yesterday stays distinct across the Monday week boundary and missing dates are safe', () => {
  const groups = groupInboxThreads([
    { id: 1, lastMessageAt: new Date(2026, 9, 11, 12).toISOString() },
    { id: 2, lastMessageAt: null },
    { id: 3, lastMessageAt: 'invalid' },
  ], new Date(2026, 9, 12, 12))
  assert.deepEqual(groups.map(group => [group.key, group.threads.map(thread => thread.id)]), [
    ['yesterday', [1]], ['earlier', [2, 3]],
  ])
})
