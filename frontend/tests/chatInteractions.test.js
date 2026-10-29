import assert from 'node:assert/strict'
import { test } from 'node:test'
import { nextTick } from 'vue'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { chatLinks } from '../src/lib/chatLinks.js'
import { setupView } from './setupView.js'

test('chat links preserve text, trim punctuation and never turn HTML or unsafe schemes into markup', () => {
  const text = 'See (https://wave.ba/a_(b)), www.wave.ba/test. <script>alert(1)</script> javascript:alert(1)'
  const parts = chatLinks(text)
  assert.equal(parts.map(part => part.text).join(''), text)
  assert.deepEqual(parts.filter(part => part.href).map(part => part.href), ['https://wave.ba/a_(b)', 'https://www.wave.ba/test'])
  assert.equal(chatLinks('https://name:password@example.com/').some(part => part.href), false)
  assert.equal(chatLinks('javascript:alert(1) data:text/html,evil').some(part => part.href), false)
  assert.deepEqual(chatLinks('Visit wave.ba/test or email name@example.com').filter(part => part.href).map(part => part.href), ['https://wave.ba/test'])
})

function pointer(x, y, extra = {}) {
  return { clientX: x, clientY: y, pointerType: 'touch', button: 0, target: { closest: () => null }, currentTarget: {}, ...extra }
}

test('mobile swipe right replies while vertical scrolling, cancelled gestures and links do not', async () => {
  const message = { id: 1, body: 'Hello', hearts: [] }
  const { state, emitted } = await setupView('../src/components/messages/ChatMessageBubble.vue', {}, { message })
  state.startGesture(pointer(10, 10))
  state.moveGesture(pointer(85, 12))
  state.endGesture()
  assert.equal(emitted[0][0], 'reply')
  assert.equal(emitted[0][1].id, 1)
  state.startGesture(pointer(10, 10))
  state.moveGesture(pointer(12, 40))
  state.moveGesture(pointer(95, 40))
  state.endGesture()
  assert.equal(emitted.length, 1)
  state.startGesture(pointer(10, 10))
  state.moveGesture(pointer(85, 12))
  state.cancelGesture()
  state.endGesture()
  assert.equal(emitted.length, 1)
  state.startGesture(pointer(10, 10, { target: { closest: () => ({}) } }))
  state.moveGesture(pointer(90, 10))
  state.endGesture()
  assert.equal(emitted.length, 1)
})

test('long press opens actions and motion cancels the press timer', async () => {
  const { state, windowTarget } = await setupView('../src/components/messages/ChatMessageBubble.vue', {}, { message: { id: 1 } })
  let callback
  let cancelled = 0
  let opened = 0
  state.actionMenu.value = { openMenu: () => { opened += 1 } }
  windowTarget.setTimeout = fn => { callback = fn; return 1 }
  windowTarget.clearTimeout = () => { cancelled += 1 }
  state.startGesture(pointer(10, 10))
  callback()
  assert.equal(opened, 1)
  let suppressed = 0
  state.handleClick({ preventDefault() { suppressed += 1 }, stopPropagation() { suppressed += 1 } })
  assert.equal(suppressed, 2) // Releasing the press must not immediately dismiss the menu.
  state.startGesture(pointer(10, 10))
  const before = cancelled
  state.moveGesture(pointer(10, 30))
  assert.ok(cancelled > before)
  assert.equal(opened, 1)
})

test('copy puts the message text on the clipboard and confirms it', async () => {
  const copied = []
  const toasts = []
  const { state } = await setupView(
    '../src/components/messages/ChatMessageBubble.vue',
    { '../../lib/toasts': { actionToasts: { notify: (message, type) => toasts.push(type) } } },
    { message: { id: 1, body: 'Pozdrav, evo brief-a' } },
    { navigator: { clipboard: { writeText: async text => { copied.push(text) } } } },
  )
  await state.copyText()
  assert.deepEqual(copied, ['Pozdrav, evo brief-a'])
  assert.deepEqual(toasts, ['success'])
})

test('reply sends the original message ID and clears it only after a successful send', async () => {
  const requests = []
  const { state } = await setupView('../src/views/MessagesView.vue', {
    '../lib/api': { apiGet: async () => {}, apiRequest: async (path, options) => {
      requests.push({ path, ...options })
      return { data: { id: 11, body: options.body.body } }
    }, formatDate: () => '', formatMoney: () => '' },
  })
  state.selectedConversationId.value = 1
  state.conversations.value = [{ id: 1, campaign: { status: 'open' } }]
  await nextTick()
  await state.replyToMessage({ id: 5, body: 'Original', senderId: 2 })
  state.draft.value = 'Reply'
  await state.sendMessage()
  assert.equal(requests[0].body.replyToId, 5)
  assert.equal(state.replyingTo.value, null)
  await state.replyToMessage({ id: 5, body: 'Original', senderId: 2 })
  state.conversations.value[0].campaign.status = 'closed'
  state.draft.value = 'Keep this reply'
  await state.sendMessage()
  assert.equal(requests.length, 1)
  assert.equal(state.replyingTo.value.id, 5)
})

test('reaction updates only an existing message without changing the preview, unread count or chronology', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.selectedInquiryId.value = 1
  state.inquiries.value = [{ id: 1, lastMessage: 'Latest', unreadCount: 3 }]
  state.conversationMessages.value = [{ id: 2, body: 'Earlier' }]
  state.handleRealtimeUpdate({ detail: { type: 'chat_reaction', inquiryId: 1, message: { id: 2, hearts: [1] } } })
  assert.deepEqual(Array.from(state.conversationMessages.value[0].hearts), [1])
  assert.equal(state.inquiries.value[0].unreadCount, 3)
  assert.equal(state.inquiries.value[0].lastMessage, 'Latest')
  state.handleRealtimeUpdate({ detail: { type: 'chat_reaction', inquiryId: 1, message: { id: 99, hearts: [1] } } })
  assert.equal(state.conversationMessages.value.length, 1)
})

test('only our ordinary messages within ten minutes expose editing, and cancel restores the draft', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.selectedConversationId.value = 1
  state.conversations.value = [{ id: 1, campaign: { status: 'open' } }]
  await nextTick()
  const message = { id: 2, senderId: 1, body: 'My message', createdAt: new Date().toISOString() }
  assert.equal(state.canModifyMessage(message), true)
  assert.equal(state.canModifyMessage({ ...message, senderId: 3 }), false)
  assert.equal(state.canModifyMessage({ ...message, event: { type: 'offered' } }), false)
  assert.equal(state.canModifyMessage({ ...message, deletedAt: new Date().toISOString() }), false)
  assert.equal(state.canModifyMessage({ ...message, createdAt: new Date(Date.now() - 600001).toISOString() }), false)
  state.draft.value = 'Unsent draft'
  state.editMessage(message)
  assert.equal(state.draft.value, 'My message')
  state.cancelEditing()
  assert.equal(state.draft.value, 'Unsent draft')
})

test('edited and deleted messages update quotes without adding timeline entries', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.conversationMessages.value = [{ id: 2, body: 'Original' }, { id: 3, body: 'Reply', replyTo: { id: 2, body: 'Original' } }]
  state.applyMessageUpdate({ id: 2, body: 'Edited', editedAt: '2026-10-25T12:00:00Z' })
  assert.equal(state.conversationMessages.value[1].replyTo.body, 'Edited')
  state.applyMessageUpdate({ id: 2, body: '', deletedAt: '2026-10-25T12:01:00Z', hearts: [] })
  assert.equal(state.conversationMessages.value[0].body, '')
  assert.equal(state.conversationMessages.value[1].replyTo.deletedAt, '2026-10-25T12:01:00Z')
  assert.equal(state.conversationMessages.value.length, 2)
})

test('the shared action menu flips above its trigger and fits a narrow mobile viewport', async () => {
  const { state, windowTarget } = await setupView('../src/components/shared/ActionMenu.vue', {}, { label: 'Options' })
  windowTarget.innerHeight = 844
  windowTarget.innerWidth = 390
  state.trigger.value = { getBoundingClientRect: () => ({ top: 760, bottom: 792, right: 380 }) }
  state.menu.value = { getBoundingClientRect: () => ({ height: 146 }), querySelectorAll: () => [] }
  state.openMenu()
  await nextTick()
  assert.equal(state.open.value, true)
  assert.equal(state.menuStyle.value.left, '196px')
  assert.equal(state.menuStyle.value.top, '610px')
  state.closeMenu()
  assert.equal(state.open.value, false)
})

test('the composer grows for wrapped drafts and only desktop Enter submits', async () => {
  const { state } = await setupView('../src/views/MessagesView.vue')
  state.selectedConversationId.value = 1
  state.conversations.value = [{ id: 1, campaign: { status: 'open' } }]
  await nextTick()
  state.composerInput.value = { style: {}, scrollHeight: 88 }
  state.draft.value = 'A longer draft\nwith another line'
  await nextTick()
  await nextTick()
  assert.equal(state.composerInput.value.style.height, '88px')
  state.composerInput.value.scrollHeight = 240
  state.resizeComposer()
  assert.equal(state.composerInput.value.style.height, '160px')
  assert.equal(state.composerInput.value.style.overflowY, 'auto')
  let submits = 0
  let prevented = 0
  const event = { key: 'Enter', currentTarget: { form: { requestSubmit() { submits += 1 } } }, preventDefault() { prevented += 1 } }
  state.handleComposerKeydown(event)
  assert.equal(submits, 1)
  assert.equal(prevented, 1)
  state.handleComposerKeydown({ ...event, shiftKey: true })
  state.handleComposerKeydown({ ...event, isComposing: true })
  state.isMobileView.value = true
  state.handleComposerKeydown(event)
  assert.equal(submits, 1)
  assert.equal(prevented, 1)
})

test('preview loading deduplicates requests and limits website lookups to two at a time', async () => {
  const pending = []
  const context = vm.createContext({})
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/chatPreviews.js', import.meta.url), 'utf8'), { context })
  await module.link(() => new vm.SyntheticModule(['apiGet'], function () {
    this.setExport('apiGet', path => new Promise((resolve, reject) => { pending.push({ path, resolve, reject }) }))
  }, { context }))
  await module.evaluate()
  const load = module.namespace.loadChatPreview
  const first = load('/preview/1', 'https://wave.ba/')
  const second = load('/preview/2', 'https://wave.ba/')
  const third = load('/preview/3', 'https://wave.ba/')
  assert.equal(load('/preview/1', 'https://wave.ba/'), first)
  assert.equal(pending.length, 2)
  pending[0].resolve({ data: { title: 'Wave' } })
  assert.equal((await first).title, 'Wave')
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(pending.length, 3)
  pending[1].reject(new Error('Unavailable site'))
  assert.equal(await second, null)
  pending[2].resolve({ data: null })
  assert.equal(await third, null)
})
