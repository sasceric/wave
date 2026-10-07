<script setup>
import AccountPage from '../components/account/AccountPage.vue'
import { onMounted, ref } from 'vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { apiRequest } from '../lib/api'

const { t } = useI18n()
const token = ref('')
const password = ref('')
const confirmation = ref('')
const busy = ref(false)
const error = ref('')
const complete = ref(false)
const invalid = ref(false)

onMounted(() => {
  token.value = window.location.hash.slice(1)
  window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`)
  invalid.value = !/^[a-f0-9]{64}$/i.test(token.value)
})

async function resetPassword() {
  error.value = ''
  if (password.value !== confirmation.value) {
    error.value = t('auth.passwordsMismatch')
    return
  }

  busy.value = true
  try {
    await apiRequest('/auth/password-resets', {
      method: 'POST',
      body: { token: token.value, password: password.value },
    })
    complete.value = true
    token.value = ''
    password.value = ''
    confirmation.value = ''
  } catch (cause) {
    error.value = cause.message
    invalid.value = cause.status === 400
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AccountPage class="account-page page-width">
    <div class="account-intro">
      <p class="eyebrow">Wave</p>
      <h1>{{ complete ? t('auth.resetComplete') : t('auth.resetTitle') }}</h1>
      <StatusMessage v-if="invalid" variant="error">
        {{ error || t('auth.resetInvalid') }}
      </StatusMessage>
      <form v-form-validation v-else-if="!complete" class="form-card auth-form" @submit.prevent="resetPassword">
        <p>{{ t('auth.resetIntro') }}</p>
        <label class="form-field">
          <span>{{ t('auth.newPassword') }}</span>
          <input v-model="password" data-validation-key="new-password" type="password" required minlength="12" maxlength="4096" autocomplete="new-password" />
          <small>{{ t('auth.passwordHint') }}</small>
        </label>
        <label class="form-field">
          <span>{{ t('auth.confirmPassword') }}</span>
          <input v-model="confirmation" data-equal-to="new-password" type="password" required minlength="12" maxlength="4096" autocomplete="new-password" />
        </label>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <button class="button button--dark button--full" type="submit" :disabled="busy">{{ t('auth.resetTitle') }} <span aria-hidden="true">↗</span></button>
      </form>
      <RouterLink v-else class="button button--dark" to="/account">{{ t('auth.backToSignIn') }} <span aria-hidden="true">↗</span></RouterLink>
      <RouterLink v-if="invalid" class="button button--outline" to="/account">{{ t('auth.backToSignIn') }} <span aria-hidden="true">↗</span></RouterLink>
    </div>
  </AccountPage>
</template>
