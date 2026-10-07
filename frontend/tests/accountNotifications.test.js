import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFile } from 'node:fs/promises'
import * as vue from 'vue'
import { setupView } from './setupView.js'

const head = (start, remaining) => ({ data: Array.from({ length: 5 }, (_, n) => ({ id: start - n, readAt: null })), meta: { remaining, nextCursor: remaining ? start - 4 : null }, unreadCount: start })

test('notification pages append five at a time and a refreshed head preserves a contiguous history', async () => {
  let response = head(20, 15)
  const paths = []
  const { state } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: async path => { paths.push(path); return response }, apiRequest: async () => {} },
  })
  await state.loadNotifications()
  assert.equal(state.notificationsCursor.value, 16)
  response = head(15, 10)
  await state.loadMoreNotifications()
  assert.equal(paths.at(-1), '/me/notifications?limit=5&before=16')
  assert.equal(state.notifications.value.length, 10)
  response = head(21, 16)
  await state.loadNotifications()
  assert.equal(state.notifications.value.length, 11)
  assert.equal(state.notificationsRemaining.value, 10)
  assert.equal(state.notificationsCursor.value, 11)
  // More than five new arrivals: reset the range so the next cursor cannot skip them.
  response = head(30, 25)
  await state.loadNotifications()
  assert.equal(state.notifications.value.length, 5)
  assert.equal(state.notificationsCursor.value, 26)
  assert.equal(state.notificationsRemaining.value, 25)
})

test('a pending older page cannot overwrite a refreshed notification range', async () => {
  let resolveOlder
  const { state } = await setupView('../src/App.vue', {
    './lib/api': { apiGet: path => path.includes('before=') ? new Promise(resolve => { resolveOlder = resolve }) : Promise.resolve(head(20, 15)), apiRequest: async () => {} },
  })
  await state.loadNotifications()
  const pending = state.loadMoreNotifications()
  await state.loadNotifications()
  resolveOlder(head(15, 10))
  await pending
  assert.equal(state.notifications.value.length, 5)
  assert.equal(state.loadingMoreNotifications.value, false)
})

test('disabled preferences suppress the bell count and enabling persists the boolean preference', async () => {
  const requests = []
  const { state, currentUser } = await setupView('../src/App.vue', {
    './lib/api': {
      apiGet: async path => { if (path === '/me/realtime') throw Object.assign(new Error('Not signed in to test realtime'), { status: 401 }); return head(20, 15) },
      apiRequest: async (path, options) => { requests.push([path, options]); return { data: { id: 1, accountType: 'creator', notificationsEnabled: true } } },
    },
  })
  currentUser.value = { id: 1, notificationsEnabled: false, profile: { displayName: 'Creator', avatarUrl: '/avatar.webp' } }
  await vue.nextTick()
  await state.loadNotifications()
  assert.equal(state.unreadNotificationCount.value, 0)
  assert.equal(state.accountName.value, 'Creator')
  assert.equal(state.accountImage.value, '/avatar.webp')
  await state.enableAccountNotifications()
  assert.equal(requests[0][0], '/me/notifications/settings')
  assert.deepEqual(JSON.parse(JSON.stringify(requests[0][1])), { method: 'PUT', body: { enabled: true } })
  assert.equal(currentUser.value.notificationsEnabled, true)
  currentUser.value = { id: 1, profile: { name: 'Company', logoUrl: '/logo.webp' } }
  assert.equal(state.accountName.value, 'Company')
  assert.equal(state.accountImage.value, '/logo.webp')
  currentUser.value = { id: 1, email: 'fallback@example.test', profile: {} }
  assert.equal(state.accountImage.value, '')
})

test('mobile notifications restore scrolling on close and keyboard focus stays inside the panel', async () => {
  let mounted, unmounted
  const body = { style: { overflow: 'auto' } }
  const document = { body, activeElement: null }
  const { state } = await setupView('../src/components/shared/NotificationsPanel.vue', {
    vue: { ...vue, onMounted: callback => { mounted = callback }, onBeforeUnmount: callback => { unmounted = callback } },
  }, { mobile: true }, { document })
  let focused = ''
  const first = { focus: () => { focused = 'first' } }
  const last = { focus: () => { focused = 'last' } }
  state.panel.value = { querySelectorAll: () => [first, last] }
  state.heading.value = { focus: () => { focused = 'heading' } }
  await mounted()
  assert.equal(body.style.overflow, 'hidden')
  assert.equal(focused, 'heading')
  let prevented = false
  document.activeElement = last
  state.trapFocus({ key: 'Tab', shiftKey: false, preventDefault: () => { prevented = true } })
  assert.equal(focused, 'first')
  assert.equal(prevented, true)
  document.activeElement = state.heading.value
  state.trapFocus({ key: 'Tab', shiftKey: true, preventDefault: () => {} })
  assert.equal(focused, 'last')
  assert.equal(state.relativeTime(new Date(Date.now() - 2 * 86400000).toISOString()), 'app.notificationTime.days')
  assert.equal(state.relativeTime('invalid'), '')
  unmounted()
  assert.equal(body.style.overflow, 'auto')
})

test('all notification and profile menu copy is available in all six locales', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) {
    const catalog = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    for (const key of ['myProfile', 'closeNotifications', 'notificationsOff', 'notificationsOffHint', 'notificationExampleOffers', 'notificationExampleMessages', 'notificationExampleDeadlines', 'unreadNotification', 'loadMoreNotifications', 'enableNotifications']) assert.ok(catalog.app[key], `${locale}: ${key}`)
    assert.ok(catalog.account.notificationsHint)
    assert.ok(catalog.campaignCard.hiringProgress)
    assert.ok(catalog.app.notificationTime.days)
  }
})
