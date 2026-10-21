<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import { setAnalyticsConsent } from '../../lib/privacyMetrics'
import { setMarketingConsent } from '../../lib/metaTracking'

const storageKey = 'wave-cookie-consent-v3'
const { t } = useI18n()
const visible = ref(false)
const analyticsAllowed = ref(false)
const marketingAllowed = ref(false)

function readStoredConsent() {
  try {
    const current = JSON.parse(localStorage.getItem(storageKey) || 'null')
    if (current?.version === 3 && typeof current.analytics === 'boolean' && typeof current.marketing === 'boolean') return current
    const previous = JSON.parse(localStorage.getItem('wave-cookie-consent-v2') || 'null')
    if (previous?.version === 2 && typeof previous.analytics === 'boolean') return { analytics: previous.analytics, marketing: false, legacy: true }
  } catch { /* Unavailable/corrupt storage never implies consent. */ }
  return null
}

function restorePreferences() {
  const consent = readStoredConsent()
  analyticsAllowed.value = consent?.analytics ?? false
  marketingAllowed.value = consent?.marketing ?? false
  visible.value = !consent || Boolean(consent.legacy)
  setAnalyticsConsent(analyticsAllowed.value)
  setMarketingConsent(marketingAllowed.value)
}

function syncPreferences(event) {
  if (event.key === storageKey || event.key === null) restorePreferences()
}

function openPreferences() {
  const consent = readStoredConsent()
  analyticsAllowed.value = consent?.analytics ?? false
  marketingAllowed.value = consent?.marketing ?? false
  visible.value = true
}

function savePreferences(allowAnalytics, allowMarketing) {
  const consent = {
    version: 3,
    essential: true,
    analytics: allowAnalytics,
    marketing: allowMarketing,
    updatedAt: new Date().toISOString(),
  }
  try { localStorage.setItem(storageKey, JSON.stringify(consent)) } catch { /* Keep this visit usable without storage. */ }
  analyticsAllowed.value = allowAnalytics
  marketingAllowed.value = allowMarketing
  setAnalyticsConsent(allowAnalytics)
  setMarketingConsent(allowMarketing)
  visible.value = false
}

onMounted(() => {
  restorePreferences()
  window.addEventListener('storage', syncPreferences)
  window.addEventListener('wave:open-cookie-settings', openPreferences)
})

onBeforeUnmount(() => {
  window.removeEventListener('wave:open-cookie-settings', openPreferences)
  window.removeEventListener('storage', syncPreferences)
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
      <label class="cookie-banner__preference">
        <input v-model="marketingAllowed" type="checkbox">
        <span>
          <strong>{{ t('legal.marketingStorage') }}</strong>
          <small>{{ t('legal.marketingStorageDescription') }}</small>
        </span>
      </label>
    </div>
    <div class="cookie-banner__actions">
      <button
        class="button button--outline cookie-banner__button"
        type="button"
        @click="savePreferences(false, false)"
      >
        {{ t('legal.rejectOptional') }}
      </button>
      <button
        class="button button--outline cookie-banner__button"
        type="button"
        @click="savePreferences(analyticsAllowed, marketingAllowed)"
      >
        {{ t('legal.savePreferences') }}
      </button>
      <button
        class="button button--dark cookie-banner__button"
        type="button"
        @click="savePreferences(true, true)"
      >
        {{ t('legal.acceptAll') }}
      </button>
    </div>
  </aside>
</template>

<style lang="scss" src="../../scss/components/shared/CookieConsentBanner.scss"></style>
