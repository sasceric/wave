export async function updateAppBadge(count, badgeNavigator = globalThis.navigator) {
  if (!Number.isSafeInteger(count) || count < 0 || !badgeNavigator?.setAppBadge) return

  try {
    if (count === 0 && badgeNavigator.clearAppBadge) {
      await badgeNavigator.clearAppBadge()
    } else {
      await badgeNavigator.setAppBadge(count)
    }
  } catch {
    // Badging is optional: denied permissions must not interrupt push delivery or the inbox.
  }
}
