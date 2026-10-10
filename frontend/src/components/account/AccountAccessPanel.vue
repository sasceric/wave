<script setup>
import AccountPage from './AccountPage.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  Building2,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  Send,
  TrendingUp,
  UserRound,
  UsersRound,
} from '@lucide/vue'
import LanguageSwitcher from '../shared/LanguageSwitcher.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import { dateOnly } from '../../lib/datePicker'
import SearchableSelect from '../shared/SearchableSelect.vue'
import WaveWordmark from '../shared/WaveWordmark.vue'
import PhoneNumberField from '../shared/PhoneNumberField.vue'
import { apiRequest } from '../../lib/api'
import { formatInternationalPhoneNumber } from '../../lib/phoneNumbers'
import { useMarketplaceCatalog } from '../../composables/useMarketplaceCatalog'
import countries from '../../data/countries.json'

const emit = defineEmits(['authenticated'])
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const { categories, industries, error: catalogError } = useMarketplaceCatalog(locale, { includeIndustries: true })
const mode = ref(route.query.mode === 'register' ? 'register' : 'login')
const busy = ref(false)
const error = ref('')
const notice = ref(route.query.deleted === '1' ? t('accountDeletion.success') : '')
const showPassword = ref(false)
const socialPending = ref(false)
const phoneCountryWasManuallySelected = ref(false)
const oauthProviders = ref({ google: false, apple: false })
const form = reactive({
  email: '',
  password: '',
  accountType: 'creator',
  name: '',
  birthday: '',
  phone: '',
  phoneCountry: defaultPhoneCountry(locale.value),
  categories: ['Lifestyle'],
  country: '',
  city: '',
  industries: [],
})
const phoneCountry = computed({
  get: () => form.phoneCountry,
  set: (countryCode) => {
    form.phoneCountry = countryCode
    phoneCountryWasManuallySelected.value = true
  },
})
const countryDisplayLocale = computed(() => {
  if (locale.value === 'cnr') return 'bs'
  if (locale.value === 'sr') return 'sr-Latn'
  return locale.value
})
const countryOptions = computed(() => {
  const countryNames = new Intl.DisplayNames([countryDisplayLocale.value], { type: 'region' })

  return countries
    .map((code) => ({ value: code, label: countryNames.of(code) || code }))
    .sort((first, second) => first.label.localeCompare(second.label, countryDisplayLocale.value))
})

function defaultPhoneCountry(currentLocale) {
  return {
    bs: 'BA',
    cnr: 'ME',
    en: 'US',
    hr: 'HR',
    sl: 'SI',
    sr: 'RS',
  }[currentLocale] || 'BA'
}

function updateRegistrationCountry(countryCode) {
  form.country = countryCode

  if (countryCode && !phoneCountryWasManuallySelected.value) {
    form.phoneCountry = countryCode
  }
}

watch(() => route.query.mode, (queryMode) => {
  if (queryMode === 'register' || queryMode === 'login') {
    mode.value = queryMode
  }
})
watch(() => form.country, (country, previousCountry) => {
  if (previousCountry && country !== previousCountry) {
    form.city = ''
  }
})

onMounted(() => {
  void loadOAuthState()
})

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
  error.value = ''
  notice.value = ''

  if (mode.value === 'register' && !form.country) {
    error.value = t('auth.countryRequired')
    return
  }

  const normalizedPhone = mode.value === 'register'
    ? formatInternationalPhoneNumber(form.phone, form.phoneCountry)
    : null
  if (mode.value === 'register' && !normalizedPhone) {
    error.value = t('auth.phoneInvalid')
    return
  }

  busy.value = true
  if (mode.value === 'reset') {
    try {
      const response = await apiRequest('/auth/password-reset-requests', {
        method: 'POST',
        body: { email: form.email },
      })
      notice.value = response.message
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
      country: form.country,
      city: form.city.trim(),
      phone: normalizedPhone,
      ...(form.accountType === 'creator'
        ? {
            category: form.categories[0],
            categories: form.categories,
            birthday: form.birthday || null,
          }
        : { industries: form.industries }),
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
  void router.replace({ query: { ...route.query, mode: nextMode } })
}
</script>

<template>
  <AccountPage
    class="account-page page-width"
    :class="{
      'account-page--auth-splash': mode === 'login',
      'account-page--registration': mode === 'register',
    }"
  >
    <header v-if="mode === 'register'" class="registration-header">
      <WaveWordmark :aria-label="t('app.homeAria')" />
      <div class="registration-header__actions">
        <LanguageSwitcher />
        <button class="registration-signin" type="button" @click="changeMode('login')">
          {{ t('auth.switchSignIn') }}
          <span aria-hidden="true">→</span>
        </button>
      </div>
    </header>

    <div class="account-intro" :class="{ 'registration-copy': mode !== 'reset' }">
      <template v-if="mode === 'register'">
        <p class="eyebrow">{{ t('auth.registrationEyebrow') }}</p>
        <h1>{{ t('auth.registrationHeroTitle') }}</h1>
        <p class="registration-copy__description">{{ t('auth.registrationHeroDescription') }}</p>
        <div class="registration-benefits">
          <article class="registration-benefit">
            <span class="registration-benefit__icon"><Send :size="20" stroke-width="1.7" aria-hidden="true" /></span>
            <span>
              <strong>{{ t('auth.registrationBenefitBrandsTitle') }}</strong>
              <small>{{ t('auth.registrationBenefitBrandsText') }}</small>
            </span>
          </article>
          <article class="registration-benefit">
            <span class="registration-benefit__icon"><TrendingUp :size="20" stroke-width="1.7" aria-hidden="true" /></span>
            <span>
              <strong>{{ t('auth.registrationBenefitGrowthTitle') }}</strong>
              <small>{{ t('auth.registrationBenefitGrowthText') }}</small>
            </span>
          </article>
          <article class="registration-benefit">
            <span class="registration-benefit__icon"><UsersRound :size="20" stroke-width="1.7" aria-hidden="true" /></span>
            <span>
              <strong>{{ t('auth.registrationBenefitSupportTitle') }}</strong>
              <small>{{ t('auth.registrationBenefitSupportText') }}</small>
            </span>
          </article>
        </div>
        <div class="registration-quote">
          <div class="registration-quote__avatars" aria-hidden="true">
            <span><UserRound :size="17" /></span>
            <span><UserRound :size="17" /></span>
            <span><UserRound :size="17" /></span>
          </div>
          <blockquote>
            “{{ t('auth.registrationTestimonial') }}”
            <cite>— {{ t('auth.registrationTestimonialBy') }}</cite>
          </blockquote>
        </div>
      </template>
      <template v-else-if="mode === 'login'">
        <p class="eyebrow">{{ t('auth.loginHeroEyebrow') }}</p>
        <h1>{{ t('auth.loginHeroTitle') }}</h1>
        <p class="registration-copy__description">{{ t('auth.loginHeroDescription') }}</p>
        <div class="registration-benefits">
          <article class="registration-benefit">
            <span class="registration-benefit__icon"><UsersRound :size="20" stroke-width="1.7" aria-hidden="true" /></span>
            <span>
              <strong>{{ t('auth.loginBenefitOneTitle') }}</strong>
              <small>{{ t('auth.loginBenefitOneText') }}</small>
            </span>
          </article>
          <article class="registration-benefit">
            <span class="registration-benefit__icon"><TrendingUp :size="20" stroke-width="1.7" aria-hidden="true" /></span>
            <span>
              <strong>{{ t('auth.loginBenefitTwoTitle') }}</strong>
              <small>{{ t('auth.loginBenefitTwoText') }}</small>
            </span>
          </article>
        </div>
      </template>
      <template v-else>
        <p class="eyebrow">Wave</p>
        <h1>{{ mode === 'login' ? t('auth.signInTitle') : t('auth.resetTitle') }}</h1>
        <p>{{ mode === 'reset' ? t('auth.resetIntro') : t('auth.creatorInvite') }}</p>
      </template>
    </div>

    <div v-if="mode === 'register'" class="registration-art" aria-hidden="true">
      <div class="registration-art__sun"></div>
      <img src="/images/man-portrait.webp" alt="" fetchpriority="high" />
    </div>
    <div v-else-if="mode === 'login'" class="auth-splash-art" aria-hidden="true">
      <img src="/images/bag.webp" alt="" fetchpriority="high" />
    </div>

    <div
      class="account-form-panel"
      :class="{
        'registration-form-panel': mode === 'register',
        'auth-splash-form-panel': mode === 'login',
      }"
    >
      <StatusMessage v-if="catalogError" variant="error">{{ catalogError }}</StatusMessage>
      <form v-form-validation
        class="form-card auth-form"
        :class="{
          'auth-form--registration': mode === 'register',
          'auth-form--splash': mode === 'login',
        }"
        @submit.prevent="submit"
      >
        <div v-if="mode === 'login'" class="login-form-heading">
          <WaveWordmark :aria-label="t('app.homeAria')" />
          <h2>{{ t('auth.signInTitle') }}</h2>
          <p>{{ t('auth.loginFormDescription') }}</p>
        </div>
        <div v-if="mode === 'register'" class="registration-form-heading">
          <h2>{{ t('auth.registrationCardTitle') }}</h2>
          <p>{{ t('auth.registrationCardDescription') }}</p>
        </div>
        <div
          v-if="mode !== 'reset' && !(mode === 'register' && socialPending)"
          class="oauth-options"
        >
          <div class="oauth-options__buttons">
            <button
              class="oauth-provider-button"
              type="button"
              :disabled="busy || !oauthProviders.google"
              @click="startOAuth('google')"
            >
              <img class="oauth-provider-button__logo" src="/images/google-logo.svg" alt="" aria-hidden="true" />
              {{ t('auth.continueWithGoogle') }}
            </button>
            <button
              class="oauth-provider-button"
              type="button"
              :disabled="busy || !oauthProviders.apple"
              @click="startOAuth('apple')"
            >
              <img class="oauth-provider-button__logo" src="/images/apple-logo.svg" alt="" aria-hidden="true" />
              {{ t('auth.continueWithApple') }}
            </button>
          </div>
          <p v-if="!oauthProviders.google || !oauthProviders.apple" class="oauth-options__note">
            {{ t('auth.socialAuthSetupRequired') }}
          </p>
          <p class="oauth-options__divider"><span>{{ t('auth.orContinueWith') }}</span></p>
        </div>
        <div v-if="mode === 'register'" class="form-grid">
        <fieldset class="form-field form-field--wide registration-type-field">
          <legend>{{ t('auth.accountType') }}</legend>
          <div class="registration-type-options">
            <label class="registration-type-option" :class="{ 'is-selected': form.accountType === 'creator' }">
              <input v-model="form.accountType" type="radio" value="creator" />
              <UserRound :size="19" stroke-width="1.7" aria-hidden="true" />
              <span>{{ t('auth.creator') }}</span>
              <span class="registration-type-option__indicator" aria-hidden="true"></span>
            </label>
            <label class="registration-type-option" :class="{ 'is-selected': form.accountType === 'company' }">
              <input v-model="form.accountType" type="radio" value="company" />
              <Building2 :size="19" stroke-width="1.7" aria-hidden="true" />
              <span>{{ t('auth.company') }}</span>
              <span class="registration-type-option__indicator" aria-hidden="true"></span>
            </label>
            </div>
        </fieldset>
        <label class="form-field form-field--wide">
          <span>{{ t('auth.name') }}</span>
          <input
            v-model.trim="form.name"
            required
            minlength="2"
            maxlength="120"
            :autocomplete="form.accountType === 'creator' ? 'name' : 'organization'"
            :placeholder="t('auth.namePlaceholder')"
          />
        </label>
        <template v-if="form.accountType === 'creator'">
          <DatePicker class="form-field--wide" v-model="form.birthday" :label="t('account.birthday')" :helper-text="t('account.birthdayPrivate')" min="1900-01-01" :max="dateOnly()" />
          <MultiSelect required
            class="form-field--wide"
            v-model="form.categories"
            :options="categories"
            :label="t('auth.category')"
            :placeholder="t('account.selectCategories')"
            :search-placeholder="t('account.searchCategories')"
            :no-results-label="t('account.noCategoriesFound')"
            :remove-label="t('account.remove')"
            :helper-text="t('account.categoriesHint')"
            :max-selections="5"
          />
        </template>
        <template v-else>
          <MultiSelect required
              v-model="form.industries"
              :options="industries || []"
              :label="t('auth.industry')"
              :placeholder="t('companyDirectory.selectIndustries')"
              :search-placeholder="t('companyDirectory.searchIndustries')"
              :no-results-label="t('companyDirectory.noIndustries')"
              :remove-label="t('account.remove')"
              :max-selections="20"
            />
        </template>
        <SearchableSelect required
          :model-value="form.country"
          :options="countryOptions"
          :label="t('auth.country')"
          :placeholder="t('auth.selectCountry')"
          :search-placeholder="t('auth.searchCountry')"
          :no-results-label="t('auth.noCountriesFound')"
          @update:model-value="updateRegistrationCountry"
        />
        <label class="form-field">
          <span>{{ t('auth.city') }}</span>
          <input
            v-model.trim="form.city"
            type="text"
            required
            maxlength="70"
            autocomplete="address-level2"
            :placeholder="t('auth.cityPlaceholder')"
          />
        </label>
        <PhoneNumberField
          v-model="form.phone"
          v-model:country-code="phoneCountry"
          :label="t('auth.phone')"
          :placeholder="t('auth.phonePlaceholder')"
          :country-label="t('auth.phoneCountry')"
          :country-placeholder="t('auth.selectCountry')"
          :country-search-placeholder="t('auth.searchPhoneCountry')"
          :no-countries-found-label="t('auth.noPhoneCountriesFound')"
        />
      </div>

      <label class="form-field">
        <span>{{ t('auth.email') }}</span>
        <div v-if="mode === 'login'" class="auth-login-input">
          <Mail :size="16" stroke-width="1.8" aria-hidden="true" />
          <input
            v-model.trim="form.email"
            type="email"
            required
            maxlength="180"
            autocomplete="email"
            :placeholder="mode === 'login' ? t('auth.emailPlaceholder') : ''"
          />
        </div>
        <input
          v-else
          v-model.trim="form.email"
          type="email"
          required
          maxlength="180"
          autocomplete="email"
          :readonly="mode === 'register' && socialPending"
        />
      </label>
      <label v-if="mode !== 'reset' && !(mode === 'register' && socialPending)" class="form-field">
        <span>{{ t('auth.password') }}</span>
        <div v-if="mode === 'login'" class="auth-login-input">
          <LockKeyhole :size="16" stroke-width="1.8" aria-hidden="true" />
          <input
            v-model="form.password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="current-password"
            :placeholder="t('auth.passwordPlaceholder')"
          />
          <button
            class="auth-login-input__toggle"
            type="button"
            :aria-label="showPassword ? t('auth.hidePassword') : t('auth.showPassword')"
            @click="showPassword = !showPassword"
          >
            <EyeOff v-if="showPassword" :size="17" stroke-width="1.8" aria-hidden="true" />
            <Eye v-else :size="17" stroke-width="1.8" aria-hidden="true" />
          </button>
        </div>
        <input
          v-else
          v-model="form.password"
          type="password"
          required
          :minlength="mode === 'register' ? 12 : 1"
          autocomplete="current-password"
        />
        <small v-if="mode === 'register'">{{ t('auth.passwordHint') }}</small>
      </label>

      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <StatusMessage v-else-if="notice">{{ notice }}</StatusMessage>
      <button
        v-if="mode === 'login'"
        class="login-forgot-password"
        type="button"
        @click="changeMode('reset')"
      >
        {{ t('auth.forgotPassword') }}
      </button>
      <button class="button button--dark button--full" type="submit" :disabled="busy">
        {{
          mode === 'login'
            ? t('auth.signIn')
            : mode === 'register'
              ? t('auth.register')
              : t('auth.requestReset')
        }}
        <span aria-hidden="true">→</span>
      </button>
      <button
        v-if="mode === 'login'"
        class="account-switch"
        type="button"
        @click="changeMode('register')"
      >
        {{ t('auth.switchSignUp') }}
      </button>
      <button
        v-else-if="mode === 'register'"
        class="account-switch"
        type="button"
        @click="changeMode('login')"
      >
        {{ t('auth.switchSignIn') }}
      </button>
      <button
        v-else
        class="account-switch"
        type="button"
        @click="changeMode('login')"
      >
        {{ t('auth.backToSignIn') }}
      </button>
      </form>
    </div>
  </AccountPage>
</template>

<style lang="scss" src="../../scss/components/account/AccountAccessPanel.scss"></style>
