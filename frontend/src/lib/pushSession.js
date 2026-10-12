import { apiRequest } from './api'

// Keep the browser subscription so the next account can bind the same device.
// Detach it on the server before invalidating the authenticated session.
export async function logoutAccount() {
  let registration
  let subscription
  try {
    registration = await globalThis.navigator?.serviceWorker?.getRegistration()
    subscription = await registration?.pushManager?.getSubscription()
  } catch {
    // The server also remembers the endpoint registered by this session.
  }
  await apiRequest('/auth/logout', {
    method: 'POST',
    body: subscription ? { pushEndpoint: subscription.endpoint } : {},
  })
  try {
    for (const notification of await registration?.getNotifications() || []) notification.close()
  } catch {
    // Notification cleanup must not turn a successful logout into an error.
  }
}
