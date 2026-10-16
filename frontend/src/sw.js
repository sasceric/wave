import { clientsClaim } from 'workbox-core'
import { precacheAndRoute } from 'workbox-precaching'
import { updateAppBadge } from './lib/appBadge'
import { requestClientNavigation } from './lib/notificationNavigation'

clientsClaim()
precacheAndRoute(self.__WB_MANIFEST)

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting()
  }
  if (event.data?.type === 'WAVE_GET_BUILD') {
    event.ports?.[0]?.postMessage({ type: 'WAVE_BUILD_ID', buildId: import.meta.env.VITE_WAVE_BUILD_ID })
  }
})

self.addEventListener('push', (event) => {
  if (!event.data) return

  let payload
  try {
    payload = event.data.json()
  } catch {
    payload = { title: 'Wave', body: 'You have a new notification.' }
  }

  event.waitUntil((async () => {
    if (!await belongsToCurrentAccount(payload.userId)) return
    await Promise.all([
      updateAppBadge(payload.badgeCount, self.navigator),
      self.registration.showNotification(payload.title || 'Wave', {
        body: payload.body || 'You have a new notification.',
        icon: '/pwa-192.png',
        badge: '/pwa-192.png',
        data: { url: payload.url || '/', userId: payload.userId },
      }),
      self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        for (const client of windows) {
          client.postMessage({ type: 'WAVE_PUSH_RECEIVED' })
        }
      }),
    ])
  })())
})

async function belongsToCurrentAccount(userId) {
  if (!Number.isSafeInteger(userId) || userId < 1) return false
  try {
    // Verify cookies at delivery time, including pushes already queued at the
    // provider before logout. Never display another account's private preview.
    const response = await fetch('/api/auth/session?locale=en', {
      credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(5000),
    })
    if (!response.ok) return false
    const { data } = await response.json()
    return data?.id === userId && data.notificationsEnabled !== false
  } catch {
    return false
  }
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  let targetUrl = new URL('/', self.location.origin)
  try {
    const requestedUrl = new URL(event.notification.data?.url || '/', self.location.origin)
    if (requestedUrl.origin === self.location.origin) targetUrl = requestedUrl
  } catch {
    // An old or malformed notification still opens Wave safely.
  }

  event.waitUntil((async () => {
    const verified = belongsToCurrentAccount(event.notification.data?.userId)
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    windows.sort((left, right) => Number(right.focused) - Number(left.focused))
    for (const client of windows) {
      if (!('focus' in client) || !('navigate' in client)) continue
      let focusedClient = client
      try {
        // Focus while the notification click's activation is available. Navigating first
        // can replace the document and leave the old WindowClient unable to focus.
        focusedClient = await client.focus() || client
      } catch {
        // Some installed iOS clients reject focus while their scene is resuming.
        // The app may still receive the routing message once restored by the OS.
      }
      if (!await verified) targetUrl = new URL('/', self.location.origin)
      if (await requestClientNavigation(focusedClient, targetUrl.href)) return focusedClient
      try {
        const navigatedClient = await focusedClient.navigate(targetUrl.href)
        if (navigatedClient) return navigatedClient
      } catch {
        // A tab can close between matchAll, focus and navigate. Try another window.
      }
    }

    // Open a safe landing page while click activation is still available.
    // Route to the private destination only after verifying the current session.
    const openedClient = await self.clients.openWindow(new URL('/', self.location.origin).href)
    // A browser may restore an installed app instead of creating a new tab.
    if (!await verified) targetUrl = new URL('/', self.location.origin)
    if (openedClient && !await requestClientNavigation(openedClient, targetUrl.href)) {
      try {
        if ('navigate' in openedClient) await openedClient.navigate(targetUrl.href)
      } catch {
        // The safe landing page remains usable if the new client closes.
      }
    }
    return openedClient
  })())
})
