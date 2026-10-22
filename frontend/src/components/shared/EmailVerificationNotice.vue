<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Mail } from '@lucide/vue'
import { currentUser, loadCurrentUser } from '../../composables/useCurrentUser'
import { useEmailVerification } from '../../composables/useEmailVerification'
import StatusMessage from './StatusMessage.vue'

const { t } = useI18n()
const { sending, notice, error, resend } = useEmailVerification(currentUser)
const refreshing = ref(false)

async function refreshVerification() {
  if (!currentUser.value || currentUser.value.emailVerified !== false || refreshing.value) return
  refreshing.value = true
  try { await loadCurrentUser() }
  catch { /* Keep the notice until verification is confirmed by the server. */ }
  finally { refreshing.value = false }
}

onMounted(() => window.addEventListener('wave:inbox-refresh', refreshVerification))
onBeforeUnmount(() => window.removeEventListener('wave:inbox-refresh', refreshVerification))
</script>

<template>
  <section v-if="currentUser?.emailVerified === false" class="email-verification-notice page-width" :aria-label="t('verificationNotice.title')">
    <div class="email-verification-notice__body">
      <Mail :size="22" aria-hidden="true" />
      <div class="email-verification-notice__copy">
        <strong>{{ t('verificationNotice.title') }}</strong>
        <p>{{ t('verificationNotice.message', { email: currentUser.email }) }}</p>
        <p>{{ t('verificationNotice.inboxHint') }}</p>
      </div>
      <button class="button button--outline" type="button" :disabled="sending" @click="resend">
        <Mail :size="16" aria-hidden="true" />{{ t(sending ? 'verificationNotice.sending' : 'account.resendVerification') }}
      </button>
    </div>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="notice">{{ notice }}</StatusMessage>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/EmailVerificationNotice.scss"></style>
