const STORAGE_KEY = 'wave.registration-intent'
const MAX_AGE = 14 * 24 * 60 * 60 * 1000

function browserStorage() {
  try { return window.localStorage } catch { return null }
}

function validTarget(path, accountType) {
  if (typeof path !== 'string') return false
  if (/^\/(?:kreatori|kampanje)\/[a-z0-9]+(?:-[a-z0-9]+)*(?:#campaign-application-title)?$/.test(path)) return true
  return accountType === 'company' && /^(?:\/(?:hr\/|si\/)?racun\/kampanje\/nova|\/(?:rs|me)\/nalog\/kampanje\/nova|\/en\/account\/campaigns\/new)$/.test(path)
}

export function rememberRegistrationIntent(path, accountType, storage = browserStorage()) {
  if (!validTarget(path, accountType) || !['creator', 'company'].includes(accountType)) return
  try {
    storage?.setItem(STORAGE_KEY, JSON.stringify({ path, accountType, userId: null, createdAt: Date.now() }))
  } catch { /* Registration must work when browser storage is unavailable. */ }
}

export function registrationReturn(user, storage = browserStorage()) {
  if (!user?.id) return null
  try {
    const pending = JSON.parse(storage?.getItem(STORAGE_KEY) || 'null')
    if (!pending) return null
    if (!validTarget(pending.path, pending.accountType) || !Number.isFinite(pending.createdAt)
      || Date.now() - pending.createdAt > MAX_AGE || pending.createdAt > Date.now()
      || pending.accountType !== user.accountType || (pending.userId !== null && pending.userId !== user.id)) {
      storage.removeItem(STORAGE_KEY)
      return null
    }
    if (pending.userId === null) {
      pending.userId = user.id
      storage.setItem(STORAGE_KEY, JSON.stringify(pending))
    }
    if (!user.approved || !user.emailVerified || user.profileComplete === false) return null
    storage.removeItem(STORAGE_KEY)
    return pending.path
  } catch {
    return null
  }
}
