export function getDropdownPlacement(anchor, menu, maxHeight = 340) {
  if (!anchor || !menu || typeof window === 'undefined') {
    return null
  }

  const anchorRect = anchor.getBoundingClientRect()
  const viewport = window.visualViewport
  const viewportTop = viewport?.offsetTop ?? 0
  const viewportBottom = viewportTop + (viewport?.height ?? window.innerHeight)
  const spaceAbove = Math.max(0, anchorRect.top - viewportTop - 8)
  const spaceBelow = Math.max(0, viewportBottom - anchorRect.bottom - 8)
  const desiredHeight = Math.min(menu.scrollHeight, maxHeight)
  const opensAbove = desiredHeight > spaceBelow && spaceAbove > spaceBelow

  return {
    opensAbove,
    maxHeight: Math.floor(Math.min(maxHeight, opensAbove ? spaceAbove : spaceBelow)),
  }
}

// A menu inside a scrolling or clipped container (e.g. the filter sidebar) would be cut off,
// so it is moved to <body> and positioned against the viewport. Dialogs keep their menus
// in place, because <body> content sits below the browser's top layer.
export function needsFloatingMenu(anchor) {
  if (!anchor || typeof window === 'undefined' || anchor.closest('dialog')) return false
  for (let element = anchor.parentElement; element && element !== document.body; element = element.parentElement) {
    const style = window.getComputedStyle(element)
    if (/(auto|scroll|hidden|clip)/.test(`${style.overflowX} ${style.overflowY}`)) return true
  }
  return false
}

export function applyFloatingMenuPosition(anchor, menu, opensAbove, { matchWidth = false, gap = 5 } = {}) {
  if (!anchor || !menu) return
  const rect = anchor.getBoundingClientRect()
  const viewportHeight = window.visualViewport?.height ?? window.innerHeight
  Object.assign(menu.style, {
    position: 'fixed',
    zIndex: '1000',
    left: `${rect.left}px`,
    right: 'auto',
    minWidth: `${rect.width}px`,
    width: matchWidth ? `${rect.width}px` : '',
    top: opensAbove ? 'auto' : `${rect.bottom + gap}px`,
    bottom: opensAbove ? `${viewportHeight - rect.top + gap}px` : 'auto',
  })
}
