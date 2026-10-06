<script setup>
import { onMounted, ref } from 'vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
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
  } catch (cause) {
    error.value = cause.message
    status.value = 'invalid'
  }
})
</script>

<template>
  <section class="account-page page-width">
    <div class="account-intro">
      <p class="eyebrow">Wave</p>
      <h1>{{ status === 'complete' ? t('auth.emailVerified') : t('auth.verifyTitle') }}</h1>
      <LoadingSkeleton v-if="status === 'pending'" :count="1" :label="t('auth.verifyingEmail')" />
      <p v-else-if="status === 'complete'" role="status">{{ t('auth.emailVerifiedPendingApproval') }}</p>
      <StatusMessage v-else-if="status === 'invalid'" variant="error">
        {{ error || t('auth.verificationInvalid') }}
      </StatusMessage>
      <RouterLink v-if="status !== 'pending'" class="button button--dark" to="/account">{{ t('auth.backToSignIn') }} <span aria-hidden="true">↗</span></RouterLink>
    </div>
  </section>
</template>
