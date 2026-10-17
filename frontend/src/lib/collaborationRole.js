/** A company can be the client in one thread and the service provider in another. */
export function isProviderInThread(thread, user) {
  if (!thread || !user) return false
  if (thread.viewerRole) return thread.viewerRole === 'provider'
  if (thread.creator?.ownerId != null) return thread.creator.ownerId === user.id
  return user.accountType === 'creator'
}
