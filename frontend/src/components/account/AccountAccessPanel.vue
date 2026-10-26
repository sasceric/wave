<script setup>
import legalDocuments from '../../../../config/legal_documents.json'
import AccountPage from './AccountPage.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  CircleCheck,
  Eye,
  EyeOff,
  KeyRound,
  LockKeyhole,
  Mail,
  MailCheck,
  UserRound,
} from '@lucide/vue'
import { I18nT, useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import LanguageSwitcher from '../shared/LanguageSwitcher.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import WaveLogo from '../shared/WaveLogo.vue'
import WaveWordmark from '../shared/WaveWordmark.vue'
import { apiRequest } from '../../lib/api'
import { trackRegistration } from '../../lib/metaTracking'
import { localizedRouteName } from '../../routePaths'

const emit = defineEmits(['authenticated'])
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const mode = ref(route.meta?.authMode || (['register', 'reset'].includes(route.query.mode) ? route.query.mode : 'login'))
const busy = ref(false)
const error = ref('')
const notice = ref(route.query.deleted === '1' ? t('accountDeletion.success') : '')
const showPassword = ref(false)
const resetSent = ref(false)
const socialPending = ref(false)
const oauthProviders = ref({ google: false, apple: false })
const form = reactive({
  email: '',
  password: '',
  accountType: route.query.accountType === 'company' ? 'company' : 'creator',
  name: '',
  newsletter: false,
  termsAccepted: false,
})

watch(() => [route.meta?.authMode, route.query.mode], ([authMode, queryMode]) => {
  mode.value = authMode || (['register', 'reset'].includes(queryMode) ? queryMode : 'login')
})
watch(() => route.query.accountType, (accountType) => {
  if (mode.value === 'register' && ['creator', 'company'].includes(accountType)) {
    form.accountType = accountType
  }
})
onMounted(() => {
  void loadOAuthState()
})

// Photo panel beside the form (desktop): one image and message per screen, following the chosen account type.
// Only files of at least ~1100px are used, so the panel stays sharp on retina screens
// (home-faq-creator-1050/1400 are 4x Real-ESRGAN upscales of the original 700px photo).
const media = computed(() => {
  if (mode.value === 'register') {
    return form.accountType === 'company'
      ? { key: 'company', src: '/images/company-cover.webp', srcset: '', position: '50% 50%', perks: 3 }
      : { key: 'creator', src: '/images/sunlit-profile.webp', srcset: '/images/sunlit-profile-768.webp 768w, /images/sunlit-profile.webp 1122w', position: '50% 30%', perks: 3 }
  }
  if (mode.value === 'reset') {
    return { key: 'reset', src: '/images/tropical-palm.webp', srcset: '', position: '30% 50%', perks: 0 }
  }
  return { key: 'login', src: '/images/home-faq-creator-1400.webp', srcset: '/images/home-faq-creator-1050.webp 1050w, /images/home-faq-creator-1400.webp 1400w', position: '50% 30%', perks: 2 }
})
// Visual hint only; the rule enforced by the form and API stays "at least 8 characters".
const passwordStrength = computed(() => {
  const value = form.password
  if (!value) return 0
  if (value.length < 8) return 1
  return value.length >= 12 || (/[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value)) ? 3 : 2
})
const passwordStrengthText = computed(() => t(['auth.passwordHint', 'authScreen.passwordTooShort', 'authScreen.passwordGood', 'authScreen.passwordStrong'][passwordStrength.value]))
const credentialsInvalid = computed(() => mode.value === 'login' && Boolean(error.value) && Boolean(form.password))

async function loadOAuthState() {
  if (route.query.oauth === 'error') {
    error.value = t('auth.socialAuthError')
  }

  try {
    const response = await apiRequest('/auth/oauth/providers')
    oauthProviders.value = {
      google: response.data?.google === true,
      apple: response.data?.apple === true,
    }
  } catch {
    error.value = t('auth.socialAuthUnavailable')
  }

  if (route.query.oauth !== 'complete' || mode.value !== 'register') return

  try {
    const response = await apiRequest('/auth/oauth/pending')
    form.email = response.data.email
    form.name = response.data.name || [response.data.firstName, response.data.lastName].filter(Boolean).join(' ')
    form.accountType = response.data.accountType
    socialPending.value = true
    notice.value = t('auth.socialAuthPending')
  } catch {
    error.value = t('auth.socialAuthExpired')
  }
}

function startOAuth(provider) {
  const query = new URLSearchParams({
    locale: locale.value,
    mode: mode.value,
    accountType: form.accountType,
  })
  window.location.assign(`/api/auth/oauth/${provider}/start?${query.toString()}`)
}

async function submit() {
  if (busy.value) return
  error.value = ''
  notice.value = ''

  busy.value = true
  if (mode.value === 'reset') {
    try {
      const response = await apiRequest('/auth/password-reset-requests', {
        method: 'POST',
        body: { email: form.email },
      })
      notice.value = response.message
      resetSent.value = true
    } catch (cause) {
      error.value = cause.message
    } finally {
      busy.value = false
    }
    return
  }

  const isRegistration = mode.value === 'register'
  const isSocialRegistration = isRegistration && socialPending.value
  const body = { email: form.email }
  if (!isSocialRegistration) body.password = form.password
  if (isRegistration) {
    Object.assign(body, {
      accountType: form.accountType,
      name: form.name.trim(),
      newsletter: form.newsletter,
      termsAccepted: form.termsAccepted,
      termsVersion: legalDocuments.version,
    })
  }

  try {
    const endpoint = isSocialRegistration
      ? '/auth/oauth/complete'
      : `/auth/${isRegistration ? 'register' : 'login'}`
    const response = await apiRequest(endpoint, {
      method: 'POST',
      body,
    })
    if (isRegistration) trackRegistration(response.data, isSocialRegistration ? 'social' : 'email')
    emit('authenticated', response.data)
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = false
  }
}

function changeMode(nextMode) {
  mode.value = nextMode
  error.value = ''
  notice.value = ''
  showPassword.value = false
  resetSent.value = false
  const query = { ...route.query }
  delete query.mode
  void router.replace({ name: localizedRouteName(nextMode === 'reset' ? 'forgot-password' : nextMode, locale.value), query })
}
</script>

<template>
  <AccountPage class="auth-page" :class="`auth-page--${mode}`">
    <section class="auth-media" aria-hidden="true">
      <img :key="media.key" :src="media.src" :srcset="media.srcset || undefined" sizes="(max-width: 900px) 1px, 50vw" :style="{ objectPosition: media.position }" alt="" fetchpriority="high" />
      <div class="auth-media__top"><span class="auth-media__logo"><WaveLogo /></span></div>
      <div class="auth-media__card">
        <p class="eyebrow">{{ t(`authScreen.media.${media.key}.eyebrow`) }}</p>
        <h2>{{ t(`authScreen.media.${media.key}.title`) }}</h2>
        <p v-if="media.key === 'login' || media.key === 'reset'">{{ t(`authScreen.media.${media.key}.text`) }}</p>
        <ul v-if="media.perks" class="auth-media__perks">
          <li v-for="index in media.perks" :key="index">
            <CircleCheck :size="16" aria-hidden="true" />
            <span><strong>{{ t(`authScreen.media.${media.key}.perk${index}Title`) }}</strong>{{ t(`authScreen.media.${media.key}.perk${index}Text`) }}</span>
          </li>
        </ul>
        <div v-if="media.key === 'creator'" class="auth-media__quote">
          <img src="/images/companies-photographer.webp" alt="" width="40" height="40" loading="lazy" />
          <p>“{{ t('auth.registrationTestimonial') }}”<small>{{ t('auth.registrationTestimonialBy') }}</small></p>
        </div>
      </div>
    </section>

    <section class="auth-panel">
      <div class="auth-panel__top">
        <WaveWordmark class="auth-panel__logo" :aria-label="t('app.homeAria')" />
        <LanguageSwitcher />
        <p class="auth-panel__switch">
          <template v-if="mode === 'login'">{{ t('authScreen.noAccountLead') }} <button type="button" @click="changeMode('register')">{{ t('authScreen.registerShort') }}</button></template>
          <template v-else-if="mode === 'register'">{{ t('authScreen.hasAccountLead') }} <button type="button" @click="changeMode('login')">{{ t('auth.signIn') }}</button></template>
          <button v-else type="button" @click="changeMode('login')">{{ t('auth.signIn') }}</button>
        </p>
      </div>

      <div class="auth-panel__body">
        <div v-if="mode === 'reset' && resetSent" class="auth-form" role="status">
          <div class="auth-form__head">
            <span class="auth-form__icon auth-form__icon--ok"><MailCheck :size="26" aria-hidden="true" /></span>
            <h1>{{ t('authScreen.resetSentTitle') }}</h1>
            <I18nT keypath="authScreen.resetSentText" tag="p"><template #email><strong>{{ form.email }}</strong></template></I18nT>
          </div>
          <button class="button button--dark button--full auth-form__submit" type="button" @click="changeMode('login')">{{ t('auth.backToSignIn') }}</button>
          <p class="auth-form__switch">{{ t('authScreen.resetNotReceived') }} <button type="button" @click="resetSent = false">{{ t('authScreen.resetSendAgain') }}</button></p>
        </div>

        <form v-else v-form-validation class="auth-form" @submit.prevent="submit">
          <div class="auth-form__head">
            <span v-if="mode === 'reset'" class="auth-form__icon"><KeyRound :size="26" aria-hidden="true" /></span>
            <h1>{{ mode === 'login' ? t('auth.signInTitle') : mode === 'register' ? t('auth.registrationCardTitle') : t('authScreen.forgotTitle') }}</h1>
            <p>{{ mode === 'login' ? t('auth.loginFormDescription') : mode === 'register' ? t('activation.signupHint') : t('authScreen.forgotIntro') }}</p>
          </div>

          <fieldset v-if="mode === 'register'" class="auth-types">
            <legend>{{ t('auth.accountType') }}</legend>
            <label class="auth-type" :class="{ 'is-selected': form.accountType === 'creator' }">
              <input v-model="form.accountType" type="radio" value="creator" :disabled="socialPending" />
              <span class="auth-type__icon"><UserRound :size="18" aria-hidden="true" /></span>
              <strong>{{ t('auth.creator') }}</strong>
              <small>{{ t('authScreen.creatorTypeHint') }}</small>
              <span class="auth-type__dot" aria-hidden="true"></span>
            </label>
            <label class="auth-type" :class="{ 'is-selected': form.accountType === 'company' }">
              <input v-model="form.accountType" type="radio" value="company" :disabled="socialPending" />
              <span class="auth-type__icon"><Building2 :size="18" aria-hidden="true" /></span>
              <strong>{{ t('auth.company') }}</strong>
              <small>{{ t('authScreen.companyTypeHint') }}</small>
              <span class="auth-type__dot" aria-hidden="true"></span>
            </label>
          </fieldset>

          <div v-if="mode !== 'reset' && !(mode === 'register' && socialPending)" class="auth-social">
            <button class="auth-social__button" type="button" :disabled="busy || !oauthProviders.google" @click="startOAuth('google')">
              <img src="/images/google-logo.svg" alt="" aria-hidden="true" width="18" height="18" />
              {{ t('auth.continueWithGoogle') }}
            </button>
            <button class="auth-social__button" type="button" :disabled="busy || !oauthProviders.apple" @click="startOAuth('apple')">
              <img class="auth-social__apple" src="/images/apple-logo.svg" alt="" aria-hidden="true" width="18" height="18" />
              {{ t('auth.continueWithApple') }}
            </button>
            <p v-if="!oauthProviders.google || !oauthProviders.apple" class="auth-social__note">{{ t('auth.socialAuthSetupRequired') }}</p>
            <p class="auth-social__divider"><span>{{ t('authScreen.orWithEmail') }}</span></p>
          </div>

          <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
          <StatusMessage v-else-if="notice && !(mode === 'reset')">{{ notice }}</StatusMessage>

          <div class="auth-fields">
            <label v-if="mode === 'register'" class="auth-field">
              <span class="auth-field__label">{{ form.accountType === 'company' ? t('authScreen.companyName') : t('authScreen.creatorName') }}</span>
              <span class="auth-input">
                <Building2 v-if="form.accountType === 'company'" :size="16" aria-hidden="true" />
                <UserRound v-else :size="16" aria-hidden="true" />
                <input
                  v-model.trim="form.name"
                  required
                  minlength="2"
                  maxlength="120"
                  :autocomplete="form.accountType === 'creator' ? 'name' : 'organization'"
                  :placeholder="form.accountType === 'company' ? t('authScreen.companyNamePlaceholder') : t('authScreen.creatorNamePlaceholder')"
                />
              </span>
            </label>

            <label class="auth-field" :class="{ 'is-invalid': credentialsInvalid }">
              <span class="auth-field__label">{{ t('auth.email') }}</span>
              <span class="auth-input">
                <Mail :size="16" aria-hidden="true" />
                <input
                  v-model.trim="form.email"
                  type="email"
                  required
                  maxlength="180"
                  autocomplete="email"
                  :placeholder="t('authScreen.emailExample')"
                  :readonly="mode === 'register' && socialPending"
                  :aria-invalid="credentialsInvalid || undefined"
                />
              </span>
            </label>

            <div v-if="mode !== 'reset' && !(mode === 'register' && socialPending)" class="auth-field" :class="{ 'is-invalid': credentialsInvalid }">
              <span class="auth-field__label">
                <label for="auth-password">{{ t('auth.password') }}</label>
                <button v-if="mode === 'login'" class="auth-field__link" type="button" @click="changeMode('reset')">{{ t('auth.forgotPassword') }}</button>
              </span>
              <span class="auth-input">
                <LockKeyhole :size="16" aria-hidden="true" />
                <input
                  id="auth-password"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  required
                  :minlength="mode === 'register' ? 8 : 1"
                  :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
                  :placeholder="mode === 'register' ? t('auth.passwordHint') : t('auth.passwordPlaceholder')"
                  :aria-invalid="credentialsInvalid || undefined"
                  :aria-describedby="mode === 'register' ? 'auth-password-hint' : undefined"
                />
                <button
                  class="auth-input__toggle"
                  type="button"
                  :aria-label="showPassword ? t('auth.hidePassword') : t('auth.showPassword')"
                  :aria-pressed="showPassword"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" :size="17" aria-hidden="true" />
                  <Eye v-else :size="17" aria-hidden="true" />
                </button>
              </span>
              <template v-if="mode === 'register'">
                <span class="auth-strength" :data-level="passwordStrength" aria-hidden="true"><i></i><i></i><i></i></span>
                <small id="auth-password-hint" class="auth-field__hint" aria-live="polite">{{ passwordStrengthText }}</small>
              </template>
            </div>
          </div>

          <label v-if="mode === 'register'" class="auth-form__check">
            <input v-model="form.termsAccepted" type="checkbox" required />
            <I18nT keypath="legal.acceptTerms" tag="span">
              <template #terms><LocalizedLink :to="{ name: 'terms-of-use' }" target="_blank">{{ t('legal.terms.title') }}</LocalizedLink></template>
            </I18nT>
          </label>

          <label v-if="mode === 'register'" class="auth-form__check">
            <input v-model="form.newsletter" type="checkbox" />
            <span><strong>{{ t('emailPreferences.registerTitle') }}</strong> {{ t('emailPreferences.registerHint') }}</span>
          </label>

          <button class="button button--dark button--full auth-form__submit" type="submit" :disabled="busy">
            {{ mode === 'login' ? t('auth.signIn') : mode === 'register' ? t('auth.register') : t('authScreen.sendResetLink') }}
            <ArrowRight :size="16" aria-hidden="true" />
          </button>

          <I18nT v-if="mode === 'register'" keypath="authScreen.privacyNotice" tag="p" class="auth-form__fine">
            <template #privacy><LocalizedLink :to="{ name: 'privacy-policy' }">{{ t('legal.privacy.title') }}</LocalizedLink></template>
          </I18nT>

          <p class="auth-form__switch">
            <template v-if="mode === 'login'">{{ t('authScreen.noAccountLead') }} <button type="button" @click="changeMode('register')">{{ t('auth.register') }}</button></template>
            <template v-else-if="mode === 'register'">{{ t('authScreen.hasAccountLead') }} <button type="button" @click="changeMode('login')">{{ t('auth.signIn') }}</button></template>
            <button v-else type="button" @click="changeMode('login')"><ArrowLeft :size="14" aria-hidden="true" />{{ t('auth.backToSignIn') }}</button>
          </p>
        </form>
      </div>

      <nav class="auth-panel__foot" :aria-label="t('app.footerLegal')">
        <LocalizedLink :to="{ name: 'imprint' }">{{ t('legal.imprint.title') }}</LocalizedLink>
        <LocalizedLink :to="{ name: 'privacy-policy' }">{{ t('legal.privacy.title') }}</LocalizedLink>
        <LocalizedLink :to="{ name: 'support' }">{{ t('support.label') }}</LocalizedLink>
      </nav>
    </section>
  </AccountPage>
</template>

<style lang="scss" src="../../scss/components/account/AccountAccessPanel.scss"></style>
