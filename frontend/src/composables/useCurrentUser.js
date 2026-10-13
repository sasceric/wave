import { ref } from 'vue'
import { apiGet } from '../lib/api'

export const currentUser = ref(null)
let sessionRevision = 0
let sessionLoaded = false

export function setCurrentUser(user) {
  sessionLoaded = true
  sessionRevision += 1
  currentUser.value = user
}

export async function loadCurrentUser() {
  const revision = ++sessionRevision
  try {
    const response = await apiGet('/auth/session')
    if (revision === sessionRevision) setCurrentUser(response.data)
  } catch (cause) {
    if (revision !== sessionRevision) return currentUser.value
    if (cause.status !== 401) {
      throw cause
    }
    setCurrentUser(null)
  }

  return currentUser.value
}

export async function ensureCurrentUser() {
  return sessionLoaded ? currentUser.value : loadCurrentUser()
}
