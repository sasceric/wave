const threshold = 96
const editable = 'input, textarea, select, [contenteditable="true"]'
const interactive = `${editable}, button, [role="dialog"], [aria-modal="true"], video`

export function isInstalledApp(windowTarget) {
  return windowTarget.navigator.standalone === true
    || windowTarget.matchMedia('(display-mode: standalone)').matches
}

export function startPullToRefresh({
  windowTarget = window,
  documentTarget = document,
  onChange,
  onRefresh,
}) {
  const root = documentTarget.documentElement
  const alreadyContained = root.classList.contains('pwa-pull-to-refresh')
  const editedForms = new Set()
  let origin = null
  let distance = 0
  let refreshing = false
  let stopped = false

  root.classList.add('pwa-pull-to-refresh')

  function clear() {
    origin = null
    distance = 0
    if (!refreshing) onChange(0, false)
  }

  function safeToRefresh() {
    for (const form of editedForms) {
      if (!form.isConnected) editedForms.delete(form)
    }
    return !refreshing
      && windowTarget.navigator.onLine !== false
      && documentTarget.visibilityState !== 'hidden'
      && editedForms.size === 0
      && !documentTarget.activeElement?.matches?.(editable)
      && !documentTarget.querySelector('[aria-modal="true"], [role="dialog"], .mobile-menu__panel, .header-user-menu__panel, .header-notifications__panel, .header-messages__panel')
      && windowTarget.getComputedStyle(documentTarget.body).overflowY !== 'hidden'
  }

  function canStart(target) {
    if (target?.closest?.(interactive)) return false
    // Leave carousels, chat histories and other nested scrolling areas alone.
    for (let element = target; element && element !== documentTarget.body && element !== root; element = element.parentElement) {
      const style = windowTarget.getComputedStyle(element)
      if ((/(auto|scroll)/.test(style.overflowY) && element.scrollHeight > element.clientHeight)
        || (/(auto|scroll)/.test(style.overflowX) && element.scrollWidth > element.clientWidth)) return false
    }
    return true
  }

  function start(event) {
    clear()
    if (event.touches.length !== 1 || !safeToRefresh()
      || windowTarget.scrollY > 0 || documentTarget.scrollingElement?.scrollTop > 0
      || !canStart(event.target)) return
    const touch = event.touches[0]
    origin = { x: touch.clientX, y: touch.clientY }
  }

  function move(event) {
    if (!origin) return
    if (event.touches.length !== 1 || event.defaultPrevented || !safeToRefresh()) return clear()
    const touch = event.touches[0]
    const dx = Math.abs(touch.clientX - origin.x)
    const dy = touch.clientY - origin.y
    if (dy < 0 || (dx > 12 && dx > dy) || !event.cancelable) return clear()
    if (dy <= 12) return
    event.preventDefault()
    distance = Math.min(dy, 144)
    onChange(distance / threshold, false)
  }

  async function end(event) {
    if (!origin) return
    if (event.touches.length) return clear()
    const shouldRefresh = distance >= threshold && safeToRefresh()
    clear()
    if (!shouldRefresh) return
    refreshing = true
    onChange(1, true)
    try {
      await onRefresh()
    } finally {
      refreshing = false
      if (!stopped) onChange(0, false)
    }
  }

  function edited(event) {
    const form = event.target?.closest?.('form, textarea, [contenteditable="true"]')
    if (form) editedForms.add(form)
    clear()
  }

  function formAction(event) {
    // Vue selects and upload drop zones don't necessarily emit native input events.
    if (event.type === 'drop' || event.target?.closest?.('button:not([role="tab"]), [role="option"]')) edited(event)
  }

  documentTarget.addEventListener('touchstart', start, { passive: true })
  documentTarget.addEventListener('touchmove', move, { passive: false })
  documentTarget.addEventListener('touchend', end)
  documentTarget.addEventListener('touchcancel', clear)
  documentTarget.addEventListener('input', edited, true)
  documentTarget.addEventListener('change', edited, true)
  documentTarget.addEventListener('click', formAction, true)
  documentTarget.addEventListener('drop', formAction, true)
  documentTarget.addEventListener('visibilitychange', clear)

  return () => {
    stopped = true
    documentTarget.removeEventListener('touchstart', start)
    documentTarget.removeEventListener('touchmove', move)
    documentTarget.removeEventListener('touchend', end)
    documentTarget.removeEventListener('touchcancel', clear)
    documentTarget.removeEventListener('input', edited, true)
    documentTarget.removeEventListener('change', edited, true)
    documentTarget.removeEventListener('click', formAction, true)
    documentTarget.removeEventListener('drop', formAction, true)
    documentTarget.removeEventListener('visibilitychange', clear)
    if (!alreadyContained) root.classList.remove('pwa-pull-to-refresh')
    clear()
  }
}
