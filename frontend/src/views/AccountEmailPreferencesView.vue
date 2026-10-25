<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Check, Mail } from '@lucide/vue'
import AccountPage from '../components/account/AccountPage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiRequest } from '../lib/api'
import { localizedRouteName } from '../routePaths'

// Opened from the footer of an optional email: works without signing in (signed link),
// unsubscribes from that email's category in one click and offers the other choices.
const CATEGORY_FIELDS = { profile_tips: 'profileTips', newsletter: 'newsletter', campaign_alerts: 'campaignAlerts' }
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const signed = computed(() => typeof route.query.u === 'string' && typeof route.query.s === 'string' ? `u=${encodeURIComponent(route.query.u)}&s=${encodeURIComponent(route.query.s)}` : '')
const status = ref('pending')
const error = ref('')
const notice = ref('')
const saving = ref(false)
const email = ref('')
const states = reactive({ profileTips: true, newsletter: true, campaignAlerts: null })
const unsubscribed = ref('')
const fields = computed(() => ['profileTips', 'newsletter', 'campaignAlerts'].filter((key) => states[key] !== null))

function apply(data) {
  email.value = data.email || ''
  states.profileTips = data.profileTips !== false
  states.newsletter = data.newsletter !== false
  states.campaignAlerts = typeof data.campaignAlerts === 'boolean' ? data.campaignAlerts : null
}

onMounted(async () => {
  if (!signed.value) {
    // Signed-in visitors manage the same choices in Postavke.
    if (currentUser.value) { void router.replace({ name: localizedRouteName('account-settings', locale.value) }); return }
    status.value = 'invalid'
    return
  }
  try {
    const category = typeof route.query.c === 'string' && CATEGORY_FIELDS[route.query.c] ? route.query.c : ''
    if (category) {
      await apiRequest(`/email/unsubscribe?c=${encodeURIComponent(category)}&${signed.value}`, { method: 'POST' })
      unsubscribed.value = CATEGORY_FIELDS[category]
      // A refresh after "undo" must not unsubscribe again.
      const query = { ...route.query }
      delete query.c
      void router.replace({ query })
    }
    apply((await apiRequest(`/email/preferences?${signed.value}`)).data)
    status.value = 'ready'
  } catch (cause) {
    error.value = cause.status === 404 || cause.status === 400 ? t('emailPreferences.invalid') : cause.message
    status.value = 'invalid'
  }
})

async function save(changes, message = t('emailPreferences.saved')) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    apply((await apiRequest(`/email/preferences?${signed.value}`, { method: 'PUT', body: changes })).data)
    notice.value = message
  } catch (cause) {
    error.value = cause.message
  } finally {
    saving.value = false
  }
}

function undo() {
  const key = unsubscribed.value
  unsubscribed.value = ''
  void save({ [key]: true }, t('emailPreferences.resubscribed', { name: t(`emailPreferences.${key}`) }))
}
</script>

<template>
  <AccountPage class="email-preferences">
    <div class="email-preferences__card">
      <span class="email-preferences__icon" :class="{ 'is-done': unsubscribed }" aria-hidden="true"><Check v-if="unsubscribed" :size="22" /><Mail v-else :size="22" /></span>
      <LoadingSkeleton v-if="status === 'pending'" :count="2" :label="t('emailPreferences.loading')" />
      <template v-else-if="status === 'ready'">
        <h1>{{ unsubscribed ? t('emailPreferences.unsubscribedTitle') : t('emailPreferences.title') }}</h1>
        <p v-if="unsubscribed" class="email-preferences__lead">{{ t('emailPreferences.unsubscribedText', { name: t(`emailPreferences.${unsubscribed}`) }) }}</p>
        <p>{{ t('emailPreferences.intro', { email }) }}</p>
        <div class="email-preferences__list">
          <SwitchField
            v-for="key in fields"
            :key="key"
            :model-value="states[key] ? 1 : 0"
            :label="t(`emailPreferences.${key}`)"
            :description="t(`emailPreferences.${key}Hint`)"
            :disabled="saving"
            @update:model-value="save({ [key]: $event === 1 })"
          />
          <div class="email-preferences__always">
            <strong>{{ t('emailPreferences.always') }}<span>{{ t('emailPreferences.alwaysTag') }}</span></strong>
            <small>{{ t('emailPreferences.alwaysHint') }}</small>
          </div>
        </div>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <p v-else-if="notice" class="email-preferences__notice" role="status">{{ notice }}</p>
        <div class="email-preferences__actions">
          <button v-if="unsubscribed" class="button button--tonal" type="button" :disabled="saving" @click="undo">{{ t('emailPreferences.undo', { name: t(`emailPreferences.${unsubscribed}`) }) }}</button>
          <LocalizedLink class="button button--dark" :to="{ name: currentUser ? 'account-dashboard' : 'home' }">{{ t(currentUser ? 'activation.backToOverview' : 'emailPreferences.toWave') }}<ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
        </div>
      </template>
      <template v-else>
        <h1>{{ t('emailPreferences.title') }}</h1>
        <StatusMessage variant="error">{{ error || t('emailPreferences.invalid') }}</StatusMessage>
        <div class="email-preferences__actions">
          <LocalizedLink class="button button--dark" :to="{ name: currentUser ? 'account-settings' : 'login' }">{{ t(currentUser ? 'account.sidebarSettings' : 'auth.signIn') }}<ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
        </div>
      </template>
    </div>
  </AccountPage>
</template>

<style lang="scss" src="../scss/views/AccountEmailPreferencesView.scss"></style>
