const completionRoutes = new Set(['account', 'account-support', 'support', 'support-create', 'support-track', 'verify-email', 'reset-password', 'imprint', 'privacy-policy', 'cookie-policy'])

export function accountAccessRedirect(route, user) {
  if (!user || user.isAdmin || user.isModerator) return null
  const name = route.meta?.routeName
  if (user.profileComplete === false && !completionRoutes.has(name)) return { name: 'account' }
  const operational = name === 'messages' || name?.startsWith('account-') && !['account-dashboard', 'account-support'].includes(name)
  if (operational && (!user.approved || !user.emailVerified)) return { name: 'account-dashboard' }
  return null
}

export function accountLanding(user) {
  return user?.profileComplete === false && !user.isAdmin && !user.isModerator ? 'account' : 'account-dashboard'
}
