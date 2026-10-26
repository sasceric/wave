<script setup>
import legalDocuments from '../../../../config/legal_documents.json'
import { ArrowRight, ChevronDown, ShieldCheck } from '@lucide/vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import SwitchField from './SwitchField.vue'
import { setAnalyticsConsent } from '../../lib/privacyMetrics'
import { setMarketingConsent } from '../../lib/metaTracking'

const storageKey = 'wave-cookie-consent-v3'
const consentLifetime = 180 * 24 * 60 * 60 * 1000
const { t } = useI18n()
const visible = ref(false)
const analyticsAllowed = ref(false)
const marketingAllowed = ref(false)
const preferencesOpen = ref(false)
const analyticsSwitch = computed({ get: () => Number(analyticsAllowed.value), set: value => { analyticsAllowed.value = value === 1 } })
const marketingSwitch = computed({ get: () => Number(marketingAllowed.value), set: value => { marketingAllowed.value = value === 1 } })

function readStoredConsent() {
  try {
    const current = JSON.parse(localStorage.getItem(storageKey) || 'null')
    const savedAt = Date.parse(current?.updatedAt)
    if (current?.version === 3 && current.policyVersion === legalDocuments.version
      && Number.isFinite(savedAt) && savedAt <= Date.now() && Date.now() - savedAt < consentLifetime
      && typeof current.analytics === 'boolean' && typeof current.marketing === 'boolean') return current
  } catch { /* Unavailable/corrupt storage never implies consent. */ }
  return null
}

function restorePreferences() {
  const consent = readStoredConsent()
  analyticsAllowed.value = consent?.analytics ?? false
  marketingAllowed.value = consent?.marketing ?? false
  preferencesOpen.value = false
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
  preferencesOpen.value = true
  visible.value = true
}

function savePreferences(allowAnalytics, allowMarketing) {
  const consent = {
    version: 3,
    policyVersion: legalDocuments.version,
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
      <header class="cookie-banner__header">
        <span class="cookie-banner__icon"><ShieldCheck :size="22" aria-hidden="true" /></span>
        <h2>{{ t('legal.cookieBannerTitle') }}</h2>
      </header>
      <p>{{ t('legal.cookieBannerDescription') }}</p>
      <LocalizedLink :to="{ name: 'cookie-policy' }" class="cookie-banner__link">
        {{ t('legal.cookies.title') }}
        <ArrowRight class="link-arrow" :size="16" aria-hidden="true" />
      </LocalizedLink>
    </div>
    <div v-if="preferencesOpen" id="wave-cookie-preferences" class="cookie-banner__preferences">
      <div class="cookie-banner__preference cookie-banner__preference--essential">
        <span>
          <strong>{{ t('legal.essentialStorage') }}</strong>
          <small>{{ t('legal.essentialStorageDescription') }}</small>
        </span>
        <span class="cookie-banner__always">{{ t('legal.alwaysActive') }}</span>
      </div>
      <SwitchField v-model="analyticsSwitch" :label="t('legal.analyticsStorage')" :description="t('legal.analyticsStorageDescription')" />
      <SwitchField v-model="marketingSwitch" :label="t('legal.marketingStorage')" :description="t('legal.marketingStorageDescription')" />
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
        @click="savePreferences(true, true)"
      >
        {{ t('legal.acceptAll') }}
      </button>
      <button v-if="preferencesOpen" class="button button--dark cookie-banner__button cookie-banner__manage" type="button" @click="savePreferences(analyticsAllowed, marketingAllowed)">
        {{ t('legal.savePreferences') }}
      </button>
      <button v-else class="button cookie-banner__button cookie-banner__manage" type="button" :aria-expanded="preferencesOpen" aria-controls="wave-cookie-preferences" @click="preferencesOpen = true">
        {{ t('legal.managePreferences') }} <ChevronDown :size="16" aria-hidden="true" />
      </button>
    </div>
  </aside>
</template>

<style lang="scss" src="../../scss/components/shared/CookieConsentBanner.scss"></style>
