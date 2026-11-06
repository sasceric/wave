<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, Mail, MailCheck, Plus, RefreshCw, Star, Trash2, Unlink } from '@lucide/vue'
import ActionMenu from '../shared/ActionMenu.vue'
import PasswordInput from '../shared/PasswordInput.vue'
import FormModal from '../shared/FormModal.vue'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import LoadingSkeleton from '../shared/LoadingSkeleton.vue'
import PasswordChangeAction from './PasswordChangeAction.vue'
import { apiGet, apiRequest } from '../../lib/api'
import { actionToasts } from '../../lib/toasts'
import { currentUser, loadCurrentUser } from '../../composables/useCurrentUser'

// Settings → E-mail adrese and Načini prijave. Errors (address on another account, last sign-in method…)
// come from the API and show as toasts through apiRequest.
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const state = ref(null)
const providersEnabled = ref({ google: false, apple: false })
const busy = ref(false)
const modal = ref(null)
const target = ref(null)
const form = reactive({ email: '', password: '' })

const PROVIDERS = [['google', 'Google', '/images/google-logo.svg'], ['apple', 'Apple', '/images/apple-logo.svg']]
const providers = computed(() => PROVIDERS
  .filter(([key]) => providersEnabled.value[key] || state.value?.identities.some(({ provider }) => provider === key))
  .map(([key, name, logo]) => ({ key, name, logo, identity: state.value?.identities.find(({ provider }) => provider === key) || null })))
const needsPassword = computed(() => state.value?.passwordKnown !== false)
const canAddMore = computed(() => (state.value?.emails.length ?? 0) < (state.value?.maxExtra ?? 3))

async function load() {
  const [signIn, oauth] = await Promise.allSettled([apiGet('/me/sign-in'), apiGet('/auth/oauth/providers')])
  if (signIn.status === 'fulfilled') state.value = signIn.value.data
  if (oauth.status === 'fulfilled') providersEnabled.value = { google: oauth.value.data?.google === true, apple: oauth.value.data?.apple === true }
}

// Back from Google/Apple: one toast for the outcome, then a clean URL.
function readOAuthResult() {
  const status = route.query.oauth
  if (typeof status !== 'string') return
  const name = route.query.provider === 'apple' ? 'Apple' : route.query.provider === 'google' ? 'Google' : ''
  const known = ['linked', 'already_linked', 'identity_taken', 'email_taken', 'provider_exists']
  actionToasts.notify(t(`signIn.oauth.${known.includes(status) ? status : 'error'}`, { provider: name || 'Google/Apple' }), status === 'linked' || status === 'already_linked' ? 'success' : 'error')
  const query = { ...route.query }
  delete query.oauth
  delete query.provider
  router.replace({ query })
}

onMounted(() => { readOAuthResult(); void load() })

function open(kind, item = null) {
  target.value = item
  form.email = ''
  form.password = ''
  modal.value = kind
}

async function run(path, options, after) {
  if (busy.value) return
  busy.value = true
  try {
    const response = await apiRequest(path, options)
    if (response?.data) state.value = response.data
    modal.value = null
    await after?.()
  } catch {
    // The toast already shows the reason (e.g. the address belongs to another account).
  } finally {
    busy.value = false
  }
}

const body = extra => (needsPassword.value ? { ...extra, password: form.password } : extra)
const addEmail = () => run('/me/emails', { method: 'POST', body: body({ email: form.email }) })
const resend = item => run(`/me/emails/${item.id}/resend`, { method: 'POST' })
const makePrimary = () => run(`/me/emails/${target.value.id}/primary`, { method: 'POST', body: body({}) }, () => loadCurrentUser().catch(() => {}))
const removeEmail = () => run(`/me/emails/${target.value.id}`, { method: 'DELETE' })
const unlink = () => run(`/me/identities/${target.value.key}`, { method: 'DELETE' })
function link() {
  window.location.assign(`/api/auth/oauth/${target.value.key}/start?${new URLSearchParams({ mode: 'link', locale: locale.value })}`)
}
</script>

<template>
  <section class="account-settings__group" aria-labelledby="settings-emails-addresses">
    <h2 id="settings-emails-addresses">{{ t('signIn.addresses') }}</h2>
    <div class="account-settings__card sign-in-settings">
      <LoadingSkeleton v-if="!state" variant="list" :count="2" :label="t('signIn.loading')" />
      <template v-else>
        <div class="sign-in-settings__main">
          <span class="sign-in-settings__main-icon"><MailCheck :size="18" aria-hidden="true" /></span>
          <span class="account-settings__text">
            <span class="sign-in-settings__eyebrow">{{ t('signIn.mainLabel') }}</span>
            <span class="sign-in-settings__address">{{ state.primary }}</span>
            <small>{{ t('signIn.primaryHint') }}</small>
          </span>
        </div>
        <div v-for="item in state.emails" :key="item.id" class="account-settings__row account-settings__row--static sign-in-settings__row">
          <span class="account-settings__icon"><Mail :size="17" aria-hidden="true" /></span>
          <span class="account-settings__text">
            <span class="sign-in-settings__address">{{ item.email }}</span>
            <small class="sign-in-settings__status" :class="{ 'sign-in-settings__status--pending': !item.verified }">{{ t(item.verified ? 'signIn.verified' : 'signIn.pending') }}</small>
            <small v-if="!item.verified">{{ t('signIn.pendingHint') }}</small>
          </span>
          <ActionMenu class="sign-in-settings__menu" :label="t('signIn.options', { email: item.email })">
            <button v-if="!item.verified" type="button" role="menuitem" class="action-menu__item" :disabled="busy" @click="resend(item)"><RefreshCw :size="17" aria-hidden="true" />{{ t('signIn.resend') }}</button>
            <button v-else type="button" role="menuitem" class="action-menu__item" :disabled="busy" @click="open('primary', item)"><Star :size="17" aria-hidden="true" />{{ t('signIn.makePrimary') }}</button>
            <button type="button" role="menuitem" class="action-menu__item action-menu__item--danger" :disabled="busy" @click="open('remove', item)"><Trash2 :size="17" aria-hidden="true" />{{ t('signIn.remove') }}</button>
          </ActionMenu>
        </div>
        <button v-if="canAddMore" type="button" class="account-settings__row sign-in-settings__add" @click="open('add')">
          <span class="account-settings__icon"><Plus :size="18" aria-hidden="true" /></span>
          <span class="account-settings__text">{{ t('signIn.add') }}</span>
        </button>
      </template>
    </div>
    <p class="sign-in-settings__note">{{ t('signIn.addressesNote') }}</p>
  </section>

  <section class="account-settings__group" aria-labelledby="settings-sign-in-methods">
    <h2 id="settings-sign-in-methods">{{ t('signIn.methods') }}</h2>
    <div class="account-settings__card sign-in-settings">
      <PasswordChangeAction :email="currentUser?.email || ''" row />
      <template v-for="provider in providers" :key="provider.key">
        <div v-if="provider.identity" class="account-settings__row account-settings__row--static sign-in-settings__row">
          <span class="account-settings__icon sign-in-settings__logo"><img :src="provider.logo" alt="" width="18" height="18" /></span>
          <span class="account-settings__text">
            <span>{{ provider.name }}</span>
            <small class="sign-in-settings__status">{{ provider.identity.email || t('signIn.linked') }}</small>
          </span>
          <ActionMenu class="sign-in-settings__menu" :label="t('signIn.options', { email: provider.name })">
            <button type="button" role="menuitem" class="action-menu__item action-menu__item--danger" :disabled="busy" @click="open('unlink', provider)"><Unlink :size="17" aria-hidden="true" />{{ t('signIn.unlinkAction') }}</button>
          </ActionMenu>
        </div>
        <button v-else type="button" class="account-settings__row sign-in-settings__row" :disabled="busy || !providersEnabled[provider.key]" @click="open('link', provider)">
          <span class="account-settings__icon sign-in-settings__logo"><img :src="provider.logo" alt="" width="18" height="18" /></span>
          <span class="account-settings__text">
            <span>{{ provider.name }}</span>
            <small>{{ t('signIn.notLinked', { provider: provider.name }) }}</small>
          </span>
          <span class="sign-in-settings__link">{{ t('signIn.link') }}<ChevronRight :size="17" aria-hidden="true" /></span>
        </button>
      </template>
    </div>
    <p class="sign-in-settings__note">{{ t('signIn.methodsNote') }}</p>
  </section>

  <FormModal :open="modal === 'add'" :title="t('signIn.addTitle')" :description="t('signIn.addHint')" :busy="busy" @update:open="!$event && (modal = null)" @submit="addEmail">
    <label><span>{{ t('signIn.newEmail') }}</span><input v-model="form.email" type="email" autocomplete="email" required maxlength="180" /></label>
    <label v-if="needsPassword"><span>{{ t('passwordChange.current') }}</span><PasswordInput v-model="form.password" autocomplete="current-password" required maxlength="4096" /></label>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('adminDashboard.cancelAction') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('signIn.sendLink') }}</button></template>
  </FormModal>

  <FormModal :open="modal === 'primary'" :title="t('signIn.primaryTitle')" :description="t('signIn.primaryConfirm', { email: target?.email || '' })" :busy="busy" @update:open="!$event && (modal = null)" @submit="makePrimary">
    <label v-if="needsPassword"><span>{{ t('passwordChange.current') }}</span><PasswordInput v-model="form.password" autocomplete="current-password" required maxlength="4096" /></label>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('adminDashboard.cancelAction') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('signIn.makePrimary') }}</button></template>
  </FormModal>

  <ConfirmationModal :open="modal === 'remove'" :title="t('signIn.removeTitle')" :message="t('signIn.removeConfirm', { email: target?.email || '' })" :confirm-label="t('signIn.remove')" :cancel-label="t('adminDashboard.cancelAction')" :loading="busy" @update:open="!$event && (modal = null)" @confirm="removeEmail" />
  <ConfirmationModal :open="modal === 'link'" :title="t('signIn.linkTitle', { provider: target?.name || '' })" :message="t('signIn.linkConfirm', { provider: target?.name || '' })" :confirm-label="t('signIn.linkContinue', { provider: target?.name || '' })" :cancel-label="t('adminDashboard.cancelAction')" @update:open="!$event && (modal = null)" @confirm="link" />
  <ConfirmationModal :open="modal === 'unlink'" :title="t('signIn.unlinkTitle', { provider: target?.name || '' })" :message="t('signIn.unlinkConfirm', { provider: target?.name || '' })" :confirm-label="t('signIn.unlink')" :cancel-label="t('adminDashboard.cancelAction')" :loading="busy" @update:open="!$event && (modal = null)" @confirm="unlink" />
</template>

<style lang="scss" src="../../scss/components/account/AccountSignInSettings.scss"></style>
