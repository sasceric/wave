import { ref, toValue, watch } from 'vue'
import { apiRequest } from '../lib/api'

const requests = new Map()

export function useEmailVerification(user) {
  const sending = ref(false)
  const notice = ref('')
  const error = ref('')
  let revision = 0

  watch([() => toValue(user)?.id, () => toValue(user)?.emailVerified], () => {
    revision++
    sending.value = false
    notice.value = ''
    error.value = ''
  }, { flush: 'sync' })

  async function resend() {
    const account = toValue(user)
    if (!account?.id || account.emailVerified !== false || sending.value) return
    const requestRevision = revision
    sending.value = true
    notice.value = ''
    error.value = ''
    try {
      if (!requests.has(account.id)) {
        const request = apiRequest('/auth/verification-email', { method: 'POST', body: {} })
          .finally(() => requests.delete(account.id))
        requests.set(account.id, request)
      }
      const response = await requests.get(account.id)
      if (requestRevision === revision) notice.value = response.message
    } catch (cause) {
      if (requestRevision === revision) error.value = cause.message
    } finally {
      if (requestRevision === revision) sending.value = false
    }
  }

  return { sending, notice, error, resend }
}
