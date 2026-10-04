import { ref } from 'vue'
import { apiGet } from '../lib/api'

export const currentUser = ref(null)

export function setCurrentUser(user) {
  currentUser.value = user
}

export async function loadCurrentUser() {
  try {
    const response = await apiGet('/auth/me')
    setCurrentUser(response.data)
  } catch (cause) {
    if (cause.status !== 401) {
      throw cause
    }
    setCurrentUser(null)
  }

  return currentUser.value
}
