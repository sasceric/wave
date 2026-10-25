<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Ban, BookOpen, ChevronRight, Coins, LifeBuoy, LogOut, Mail, Star } from '@lucide/vue'
import AccountPage from '../components/account/AccountPage.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AccountDeletionAction from '../components/account/AccountDeletionAction.vue'
import CampaignAlertSettings from '../components/account/CampaignAlertSettings.vue'
import PasswordChangeAction from '../components/account/PasswordChangeAction.vue'
import PublicUrlAction from '../components/account/PublicUrlAction.vue'
import LanguageSwitcher from '../components/shared/LanguageSwitcher.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import { currentUser, setCurrentUser } from '../composables/useCurrentUser'
import { creditAccount, refreshCredits } from '../composables/useCredits'
import { apiRequest } from '../lib/api'
import { logoutAccount } from '../lib/pushSession'
import { localizedPath } from '../routePaths'

// One settings page for every account: the links and switches that used to be
// spread across the sidebar, the profile summary and the overview.
const { t, locale } = useI18n()
const router = useRouter()
const user = computed(() => currentUser.value)
const creator = computed(() => user.value?.accountType === 'creator')
const operational = computed(() => Boolean(user.value?.isAdmin || (user.value?.approved && user.value?.emailVerified !== false && user.value?.profileComplete !== false)))
const visibilityEligible = computed(() => Boolean(user.value?.approved && user.value?.emailVerified && user.value?.profileComplete))
const profile = computed(() => user.value?.profile || null)
const guidesRoute = computed(() => creator.value ? 'creator-guides' : 'seo-guides')
const visibilitySaving = ref(false)
const notificationsSaving = ref(false)
const signingOut = ref(false)
const error = ref('')
const notice = ref('')

watch([() => user.value?.id, locale], () => { if (operational.value) refreshCredits().catch(() => {}) }, { immediate: true })

async function updateVisibility(value) {
  if (!visibilityEligible.value || visibilitySaving.value) return
  const id = user.value.id
  visibilitySaving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await apiRequest('/me/account-visibility', { method: 'PUT', body: { hide_my_account: value ? 0 : 1 } })
    if (currentUser.value?.id === id) { setCurrentUser(response.data); notice.value = t('account.visibilitySaved') }
  } catch (cause) {
    if (currentUser.value?.id === id) error.value = cause.message || t('account.visibilityError')
  } finally {
    visibilitySaving.value = false
  }
}

async function updateNotifications(enabled) {
  if (!user.value || notificationsSaving.value) return
  const id = user.value.id
  notificationsSaving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await apiRequest('/me/notifications/settings', { method: 'PUT', body: { enabled: enabled === 1 } })
    if (currentUser.value?.id === id) setCurrentUser(response.data)
  } catch (cause) {
    if (currentUser.value?.id === id) error.value = cause.message
  } finally {
    notificationsSaving.value = false
  }
}

async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  error.value = ''
  try {
    await logoutAccount()
    setCurrentUser(null)
    await router.replace({ path: localizedPath('login', locale.value) })
  } catch (cause) {
    error.value = cause.message
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <AccountPage class="account-settings">
    <AccountSidebar v-if="user" :user="user" />
    <header class="account-settings__heading">
      <p class="eyebrow">{{ t('app.account') }}</p>
      <h1>{{ t('account.sidebarSettings') }}</h1>
      <p>{{ t('accountHub.settingsIntro') }}</p>
    </header>
    <LocalizedLink v-if="!user" class="button button--dark" :to="{ name: 'login' }">{{ t('auth.signIn') }}</LocalizedLink>
    <template v-else>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <StatusMessage v-else-if="notice" variant="success">{{ notice }}</StatusMessage>

      <section class="account-settings__group" aria-labelledby="settings-account">
        <h2 id="settings-account">{{ t('accountHub.groupAccount') }}</h2>
        <div class="account-settings__card">
          <div class="account-settings__row account-settings__row--static">
            <span class="account-settings__icon"><Mail :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('accountHub.email') }}<small>{{ user.email }}</small></span>
          </div>
          <PasswordChangeAction :email="user.email" row />
          <PublicUrlAction v-if="profile?.slug && user.profileComplete !== false" :profile="profile" :kind="creator ? 'creator' : 'company'" row />
          <div class="account-settings__row account-settings__row--language">
            <span class="account-settings__text">{{ t('app.language') }}</span>
            <LanguageSwitcher expanded />
          </div>
        </div>
      </section>

      <section class="account-settings__group" aria-labelledby="settings-notifications">
        <h2 id="settings-notifications">{{ t('app.notifications') }}</h2>
        <div class="account-settings__card">
          <SwitchField :model-value="user.notificationsEnabled === false ? 0 : 1" :label="t('app.notifications')" :description="t('account.notificationsHint')" :disabled="notificationsSaving || !user.emailVerified" @update:model-value="updateNotifications" />
        </div>
        <CampaignAlertSettings v-if="creator && operational" :key="user.id" />
      </section>

      <section class="account-settings__group" aria-labelledby="settings-privacy">
        <h2 id="settings-privacy">{{ t('accountHub.groupPrivacy') }}</h2>
        <div class="account-settings__card">
          <SwitchField :model-value="user.hide_my_account === 0 ? 1 : 0" :label="t('activation.publicProfile')" :description="t(visibilityEligible ? 'activation.visibilityHint' : 'activation.visibilityLocked')" :disabled="!visibilityEligible || visibilitySaving" @update:model-value="updateVisibility($event === 1)" />
          <LocalizedLink v-if="operational" class="account-settings__row" :to="{ name: 'account-blocks' }">
            <span class="account-settings__icon"><Ban :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('safety.myBlocks') }}<small>{{ t('accountHub.blocksHint') }}</small></span>
            <ChevronRight class="account-settings__arrow" :size="18" aria-hidden="true" />
          </LocalizedLink>
        </div>
      </section>

      <section v-if="operational" class="account-settings__group" aria-labelledby="settings-money">
        <h2 id="settings-money">{{ t('accountHub.groupMoney') }}</h2>
        <div class="account-settings__card">
          <LocalizedLink class="account-settings__row" :to="{ name: 'account-credits' }">
            <span class="account-settings__icon"><Coins :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('credits.title') }}<small>{{ t('accountHub.creditsHint') }}</small></span>
            <strong v-if="creditAccount" class="account-settings__value">{{ creditAccount.unlimited ? '∞' : creditAccount.balance }}</strong>
            <ChevronRight class="account-settings__arrow" :size="18" aria-hidden="true" />
          </LocalizedLink>
          <LocalizedLink class="account-settings__row" :to="{ name: 'account-reviews' }">
            <span class="account-settings__icon"><Star :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('safety.myReviews') }}<small>{{ t('accountHub.reviewsHint') }}</small></span>
            <ChevronRight class="account-settings__arrow" :size="18" aria-hidden="true" />
          </LocalizedLink>
        </div>
      </section>

      <section class="account-settings__group" aria-labelledby="settings-help">
        <h2 id="settings-help">{{ t('accountHub.groupHelp') }}</h2>
        <div class="account-settings__card">
          <LocalizedLink class="account-settings__row" :to="{ name: 'account-support' }">
            <span class="account-settings__icon"><LifeBuoy :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('support.myTickets') }}<small>{{ t('accountHub.ticketsHint') }}</small></span>
            <ChevronRight class="account-settings__arrow" :size="18" aria-hidden="true" />
          </LocalizedLink>
          <LocalizedLink class="account-settings__row" :to="{ name: guidesRoute }">
            <span class="account-settings__icon"><BookOpen :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('accountHub.guides') }}<small>{{ t('accountHub.guidesHint') }}</small></span>
            <ChevronRight class="account-settings__arrow" :size="18" aria-hidden="true" />
          </LocalizedLink>
        </div>
      </section>

      <section class="account-settings__group" :aria-label="t('account.signOut')">
        <div class="account-settings__card">
          <button class="account-settings__row" type="button" :disabled="signingOut" @click="signOut">
            <span class="account-settings__icon"><LogOut :size="18" aria-hidden="true" /></span>
            <span class="account-settings__text">{{ t('account.signOut') }}</span>
          </button>
          <AccountDeletionAction v-if="!user.isAdmin && !user.isModerator" row />
        </div>
      </section>
    </template>
  </AccountPage>
</template>

<style lang="scss" src="../scss/views/AccountSettingsView.scss"></style>
