<script setup>
import { ArrowRight } from '@lucide/vue'
import AccountPage from '../components/account/AccountPage.vue'
import { onMounted, ref } from 'vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser, loadCurrentUser } from '../composables/useCurrentUser'
import { apiRequest } from '../lib/api'

const { t } = useI18n()
const status = ref('pending')
const error = ref('')

onMounted(async () => {
  const token = window.location.hash.slice(1)
  window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`)
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    status.value = 'invalid'
    return
  }

  try {
    await apiRequest('/auth/verify-email', { method: 'POST', body: { token } })
    status.value = 'complete'
    // Verification may have happened in another tab; refresh the authenticated session, never infer its identity from the token.
    void loadCurrentUser().catch(() => {})
  } catch (cause) {
    error.value = cause.message
    status.value = 'invalid'
  }
})
</script>

<template>
  <AccountPage class="account-page page-width">
    <div class="account-intro">
      <p class="eyebrow">Wave</p>
      <h1>{{ status === 'complete' ? t('auth.emailVerified') : t('auth.verifyTitle') }}</h1>
      <LoadingSkeleton v-if="status === 'pending'" :count="1" :label="t('auth.verifyingEmail')" />
      <p v-else-if="status === 'complete'" role="status">{{ t('auth.emailVerifiedPendingApproval') }}</p>
      <StatusMessage v-else-if="status === 'invalid'" variant="error">
        {{ error || t('auth.verificationInvalid') }}
      </StatusMessage>
      <RouterLink v-if="status !== 'pending'" class="button button--dark" :to="{ name: currentUser ? 'account-dashboard' : 'account' }">{{ t(currentUser ? 'activation.backToOverview' : 'auth.backToSignIn') }} <ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></RouterLink>
    </div>
  </AccountPage>
</template>
