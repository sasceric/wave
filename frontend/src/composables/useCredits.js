import { ref } from 'vue'
import { apiGet } from '../lib/api'
import { currentUser } from './useCurrentUser'

export const creditAccount = ref(null)
let pending = null
let ownerId = null

export function setCreditAccount(value) {
  ownerId = currentUser.value?.id ?? null
  creditAccount.value = value
}

export async function refreshCredits() {
  const id = currentUser.value?.id
  if (!id) {
    ownerId = null
    creditAccount.value = null
    return null
  }
  if (pending && ownerId === id) return pending
  if (ownerId !== id) creditAccount.value = null
  ownerId = id
  const request = apiGet('/me/credits').then(({ data }) => {
    if (currentUser.value?.id === id) creditAccount.value = data
    return data
  }).finally(() => { if (pending === request) pending = null })
  pending = request
  return request
}
