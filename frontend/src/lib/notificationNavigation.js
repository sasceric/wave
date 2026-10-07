export function notificationDestination(notification) {
  if (notification.conversationId) {
    return { name: 'messages', query: { conversation: notification.conversationId } }
  }
  const destinations = {
    application_received: 'account-campaigns',
    application_shortlisted: 'account-applications',
    application_rejected: 'account-applications',
    campaign_invitation: 'account-invitations',
    invitation_accepted: 'account-campaigns',
    invitation_declined: 'account-campaigns',
    offer_received: 'account-offers',
    offer_accepted: 'account-campaigns',
    offer_declined: 'account-campaigns',
    creator_inquiry_received: 'account-inquiries',
    creator_inquiry_accepted: 'account-inquiries',
    creator_inquiry_rejected: 'account-inquiries',
  }
  return { name: destinations[notification.type] || 'account' }
}

export function requestClientNavigation(client, url, {
  Channel = globalThis.MessageChannel,
  timeoutMs = 1500,
} = {}) {
  if (!Channel || !client?.postMessage) return Promise.resolve(false)

  return new Promise((resolve) => {
    const channel = new Channel()
    let finished = false
    const finish = (opened) => {
      if (finished) return
      finished = true
      clearTimeout(timeout)
      channel.port1.close()
      channel.port2.close()
      resolve(opened)
    }
    // One bounded acknowledgement deadline, not inbox polling. Older clients
    // without the routing listener fall back to WindowClient.navigate().
    const timeout = setTimeout(() => finish(false), timeoutMs)
    channel.port1.onmessage = (event) => finish(event.data?.type === 'WAVE_NOTIFICATION_OPENED')
    try {
      client.postMessage({ type: 'WAVE_OPEN_NOTIFICATION', url }, [channel.port2])
    } catch {
      finish(false)
    }
  })
}

export function startNotificationNavigation(navigate, {
  serviceWorker = globalThis.navigator?.serviceWorker,
  origin = globalThis.location?.origin,
} = {}) {
  async function handleNavigation(event) {
    if (event.data?.type !== 'WAVE_OPEN_NOTIFICATION') return
    const reply = event.ports?.[0]
    try {
      if (typeof event.data.url !== 'string' || !event.data.url) throw new Error('Notification target is missing.')
      const target = new URL(event.data.url, origin)
      if (target.origin !== origin) throw new Error('Notification target must belong to Wave.')
      if (await navigate(`${target.pathname}${target.search}${target.hash}`) === false) {
        throw new Error('Notification navigation was interrupted.')
      }
      reply?.postMessage({ type: 'WAVE_NOTIFICATION_OPENED' })
    } catch {
      reply?.postMessage({ type: 'WAVE_NOTIFICATION_NAVIGATION_FAILED' })
    }
  }

  serviceWorker?.addEventListener('message', handleNavigation)
  return () => serviceWorker?.removeEventListener('message', handleNavigation)
}
