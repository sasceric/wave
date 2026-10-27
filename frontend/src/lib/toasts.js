import { shallowRef } from 'vue'

export function createToastStore({ now = Date.now, schedule = setTimeout, cancel = clearTimeout } = {}) {
  const messages = shallowRef([])
  const timers = new Map()
  let nextId = 0
  function dismiss(id) {
    cancel(timers.get(id)?.timer)
    timers.delete(id)
    messages.value = messages.value.filter(message => message.id !== id)
  }
  function resume(id, reason = 'interaction') {
    const timing = timers.get(id)
    if (!timing) return
    timing.paused.delete(reason)
    if (timing.paused.size || timing.timer !== null) return
    timing.started = now()
    timing.timer = schedule(() => dismiss(id), timing.remaining)
    timing.timer?.unref?.()
  }
  function pause(id, reason = 'interaction') {
    const timing = timers.get(id)
    if (!timing) return
    timing.paused.add(reason)
    if (timing.timer === null) return
    cancel(timing.timer)
    timing.timer = null
    timing.remaining = Math.max(0, timing.remaining - (now() - timing.started))
  }
  function notify(text, variant = 'success') {
    if (typeof text !== 'string' || !text.trim()) return null
    const message = text.trim()
    const duplicate = messages.value.find(item => item.message === message && item.variant === variant)
    if (duplicate) dismiss(duplicate.id)
    const id = ++nextId
    // Keep the newest feedback visible without letting a burst cover the screen.
    if (messages.value.length >= 3) dismiss(messages.value[0].id)
    messages.value = [...messages.value, { id, message, variant }]
    timers.set(id, { timer: null, remaining: variant === 'error' ? 10000 : 5000, started: now(), paused: new Set() })
    resume(id)
    return id
  }
  function clear() { for (const item of messages.value) dismiss(item.id) }
  return { messages, notify, dismiss, pause, resume, clear }
}

export const actionToasts = createToastStore()
