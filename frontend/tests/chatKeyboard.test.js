import assert from 'node:assert/strict'
import { test } from 'node:test'
import { setupView } from './setupView.js'

async function mobileChat(extraModules = {}) {
  const view = await setupView('../src/views/MessagesView.vue', extraModules)
  const { state, windowTarget, documentTarget } = view
  windowTarget.matchMedia = () => ({ matches: true })
  windowTarget.innerWidth = 428
  windowTarget.innerHeight = 926
  documentTarget.documentElement.clientHeight = 926
  windowTarget.visualViewport = { height: 926, offsetTop: 0, scale: 1 }
  state.updateViewport()
  return view
}

test('Send retains composer focus without intercepting other pointers or desktop activation', async () => {
  const { state, documentTarget } = await mobileChat()
  state.composerInput.value = {}
  documentTarget.activeElement = state.composerInput.value
  let prevented = 0
  const preventDefault = () => { prevented += 1 }
  state.preserveComposerFocus({ button: 0, isPrimary: true, preventDefault })
  state.preserveComposerFocus({ button: 0, preventDefault }) // Mouse fallback.
  assert.equal(prevented, 2)
  state.preserveComposerFocus({ button: 2, isPrimary: true, preventDefault })
  state.preserveComposerFocus({ button: 0, isPrimary: false, preventDefault })
  documentTarget.activeElement = {}
  state.preserveComposerFocus({ button: 0, preventDefault })
  documentTarget.activeElement = state.composerInput.value
  state.isMobileView.value = false
  state.preserveComposerFocus({ button: 0, preventDefault })
  assert.equal(prevented, 2)
})

test('a mobile submit restores focus inside the gesture before the request, never after its response', async () => {
  const order = []
  let finishSend
  const { state, documentTarget } = await mobileChat({
    '../lib/api': {
      apiGet: async () => {}, formatDate: () => '', formatMoney: () => '',
      apiRequest: () => {
        order.push('request')
        return new Promise((resolve) => { finishSend = resolve })
      },
    },
  })
  state.conversations.value = [{ id: 1, unreadCount: 0 }]
  state.selectedConversationId.value = 1
  state.draft.value = 'Hello 😀'
  state.keyboardOpen.value = true
  state.composerInput.value = { focus: (options) => { assert.equal(options.preventScroll, true); order.push('focus') } }
  const submitter = {}
  documentTarget.activeElement = submitter
  const sending = state.sendMessage({ submitter })
  assert.deepEqual(order, ['focus', 'request'])
  // The user can move focus while the request is in flight.
  documentTarget.activeElement = {}
  finishSend({ data: { id: 1, body: 'Hello 😀', senderId: 1, createdAt: '2026-10-06T10:00:00Z' } })
  await sending
  assert.equal(state.draft.value, '')
  assert.deepEqual(order, ['focus', 'request'])
  state.focusComposerForSend()
  assert.deepEqual(order, ['focus', 'request'])
  // Touch browsers can leave focus on the document rather than on Send.
  state.focusComposerForSend({ submitter })
  assert.deepEqual(order, ['focus', 'request', 'focus'])
  state.keyboardOpen.value = false
  state.focusComposerForSend({ submitter })
  state.keyboardOpen.value = true
  state.isMobileView.value = false
  state.focusComposerForSend({ submitter })
  assert.deepEqual(order, ['focus', 'request', 'focus'])
})

test('installed PWA layout keeps the idle height when every viewport shrinks, including emoji changes', async () => {
  const { state, windowTarget, documentTarget } = await mobileChat()
  // Some WebKit versions resize metrics before delivering the focus event.
  windowTarget.innerHeight = 480
  documentTarget.documentElement.clientHeight = 480
  windowTarget.visualViewport.height = 480
  windowTarget.visualViewport.offsetTop = 20
  state.handleComposerFocus()
  assert.equal(state.keyboardOpen.value, true)
  assert.equal(state.viewportStyle.value['--chat-bottom-inset'], '0px')
  assert.equal(state.visualViewportHeight.value, 480)
  assert.equal(state.visualViewportTop.value, 20)
  windowTarget.visualViewport.height = 390
  windowTarget.visualViewport.offsetTop = 35
  state.updateViewport()
  assert.equal(state.visualViewportHeight.value, 390)
  assert.equal(state.visualViewportTop.value, 35)
  state.handleComposerBlur()
  windowTarget.innerHeight = 926
  documentTarget.documentElement.clientHeight = 926
  windowTarget.visualViewport.height = 926
  // Ignore a stale keyboard pan offset once the keyboard closes.
  state.updateViewport()
  assert.equal(state.keyboardOpen.value, false)
  assert.equal(state.visualViewportTop.value, 0)
  assert.equal(state.viewportStyle.value['--chat-bottom-inset'], 'env(safe-area-inset-bottom, 0px)')
  windowTarget.innerWidth = 926
  windowTarget.innerHeight = 428
  documentTarget.documentElement.clientHeight = 428
  windowTarget.visualViewport.height = 428
  state.updateViewport()
  assert.equal(state.keyboardOpen.value, false)
})

test('late keyboard geometry is measured with bounded callbacks and cancelled on hide or cleanup', async () => {
  let requests = 0
  const { state, windowTarget, documentTarget } = await mobileChat({
    '../lib/api': { apiGet: () => { requests += 1 }, apiRequest: () => { requests += 1 }, formatDate: () => '', formatMoney: () => '' },
  })
  const timers = new Map()
  let nextTimer = 0
  let frame
  windowTarget.setTimeout = (callback, delay) => { timers.set(++nextTimer, { callback, delay }); return nextTimer }
  windowTarget.clearTimeout = (id) => timers.delete(id)
  windowTarget.requestAnimationFrame = (callback) => { frame = callback; return 1 }
  windowTarget.cancelAnimationFrame = () => { frame = null }
  state.handleComposerFocus()
  assert.deepEqual(Array.from(timers.values(), ({ delay }) => delay), [100, 350, 750])
  windowTarget.visualViewport.height = 420 // No additional resize event.
  timers.get(1).callback()
  assert.equal(state.visualViewportHeight.value, 420)
  assert.equal(state.keyboardOpen.value, true)
  state.scheduleViewportUpdate()
  assert.equal(timers.size, 3) // A new event replaces the pending checks.
  documentTarget.visibilityState = 'hidden'
  state.scheduleViewportUpdate()
  assert.equal(timers.size, 0)
  assert.equal(frame, null)
  documentTarget.visibilityState = 'visible'
  state.scheduleViewportUpdate()
  state.clearViewportUpdates()
  assert.equal(timers.size, 0)
  assert.equal(frame, null)
  assert.equal(requests, 0)
})
