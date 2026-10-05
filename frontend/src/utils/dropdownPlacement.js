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
