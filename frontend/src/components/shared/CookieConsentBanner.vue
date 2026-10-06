<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import { setAnalyticsConsent } from '../../lib/privacyMetrics'

const storageKey = 'wave-cookie-consent-v2'
const { t } = useI18n()
const visible = ref(false)
const analyticsAllowed = ref(false)

function readStoredConsent() {
  const storedValue = localStorage.getItem(storageKey)
  if (!storedValue) {
    return null
  }

  let consent
  try {
    consent = JSON.parse(storedValue)
  } catch (error) {
    if (!(error instanceof SyntaxError)) {
      throw error
    }
    return null
  }

  if (consent?.version !== 2 || typeof consent.analytics !== 'boolean') {
    return null
  }

  return consent
}

function openPreferences() {
  analyticsAllowed.value = readStoredConsent()?.analytics ?? false
  visible.value = true
}

function savePreferences(allowAnalytics) {
  const consent = {
    version: 2,
    essential: true,
    analytics: allowAnalytics,
    updatedAt: new Date().toISOString(),
  }
  localStorage.setItem(storageKey, JSON.stringify(consent))
  analyticsAllowed.value = allowAnalytics
  setAnalyticsConsent(allowAnalytics)
  visible.value = false
}

onMounted(() => {
  const consent = readStoredConsent()
  analyticsAllowed.value = consent?.analytics ?? false
  visible.value = consent === null
  setAnalyticsConsent(analyticsAllowed.value)
  window.addEventListener('wave:open-cookie-settings', openPreferences)
})

onBeforeUnmount(() => {
  window.removeEventListener('wave:open-cookie-settings', openPreferences)
})

defineExpose({ open: openPreferences })
</script>

<template>
  <aside
    v-if="visible"
    class="cookie-banner"
    role="region"
    :aria-label="t('legal.cookieBannerTitle')"
  >
    <div class="cookie-banner__copy">
      <span class="cookie-banner__eyebrow">{{ t('legal.cookieBannerEyebrow') }}</span>
      <h2>{{ t('legal.cookieBannerTitle') }}</h2>
      <p>{{ t('legal.cookieBannerDescription') }}</p>
      <LocalizedLink :to="{ name: 'cookie-policy' }" class="cookie-banner__link">
        {{ t('legal.cookies.title') }}
        <span aria-hidden="true">↗</span>
      </LocalizedLink>
    </div>
    <div class="cookie-banner__preferences">
      <div class="cookie-banner__preference cookie-banner__preference--essential">
        <span class="cookie-banner__check" aria-hidden="true">✓</span>
        <span>
          <strong>{{ t('legal.essentialStorage') }}</strong>
          <small>{{ t('legal.essentialStorageDescription') }}</small>
        </span>
      </div>
      <label class="cookie-banner__preference">
        <input v-model="analyticsAllowed" type="checkbox">
        <span>
          <strong>{{ t('legal.analyticsStorage') }}</strong>
          <small>{{ t('legal.analyticsStorageDescription') }}</small>
        </span>
      </label>
    </div>
    <div class="cookie-banner__actions">
      <button
        class="button button--outline cookie-banner__button"
        type="button"
        @click="savePreferences(false)"
      >
        {{ t('legal.rejectOptional') }}
      </button>
      <button
        class="button button--outline cookie-banner__button"
        type="button"
        @click="savePreferences(analyticsAllowed)"
      >
        {{ t('legal.savePreferences') }}
      </button>
      <button
        class="button button--dark cookie-banner__button"
        type="button"
        @click="savePreferences(true)"
      >
        {{ t('legal.acceptAll') }}
      </button>
    </div>
  </aside>
</template>

<style lang="scss" src="./CookieConsentBanner.scss"></style>
