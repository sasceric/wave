<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Copy, Download, QrCode, Share2 } from '@lucide/vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, formatDate } from '../lib/api'
import { qrImages } from '../lib/qrImage'
import { actionToasts } from '../lib/toasts'

// Pozovi kreatore: the creator's link, progress to 20/35/50 approved creators, tiers and who registered.
const { t, locale } = useI18n()
const data = ref(null)
const loading = ref(false)
const error = ref('')
const copied = ref(false)
const qr = ref('')
const qrOpen = ref(false)
const creator = computed(() => currentUser.value?.accountType === 'creator')
let ticket = 0
let copiedTimer = 0

watch([() => currentUser.value?.id, () => currentUser.value?.accountType, locale], load, { immediate: true })

async function load() {
  const current = ++ticket
  data.value = null
  qr.value = ''
  qrOpen.value = false
  copied.value = false
  clearTimeout(copiedTimer)
  error.value = ''
  loading.value = false
  if (!currentUser.value || !creator.value) return
  loading.value = true
  try {
    const response = await apiGet('/me/referrals')
    if (current !== ticket) return
    data.value = response.data
    const generated = await qrImages(response.data.link)
    if (current === ticket) qr.value = generated.png
  } catch (cause) {
    if (current === ticket) error.value = cause.message
  } finally {
    if (current === ticket) loading.value = false
  }
}

onBeforeUnmount(() => { ++ticket; clearTimeout(copiedTimer) })

const counted = computed(() => data.value?.counted ?? 0)
const next = computed(() => (data.value?.tiers || []).find(tier => counted.value < tier.at) || null)
const nextText = computed(() => next.value
  ? t('referrals.next', { count: next.value.at - counted.value, tier: t(`referrals.tiers.${next.value.key}`) })
  : t('referrals.allDone'))
const fill = computed(() => `${Math.min(100, (counted.value / 50) * 100)}%`)
const tiers = computed(() => (data.value?.tiers || []).map((tier) => {
  const state = tier.approvedAt ? 'active' : tier.reachedAt ? 'waiting' : next.value?.at === tier.at ? 'next' : 'locked'
  return {
    ...tier,
    state,
    stateLabel: state === 'next' ? t('referrals.state.left', { count: tier.at - counted.value }) : t(`referrals.state.${state}`),
    perks: [1, 2, 3].map(index => t(`referrals.perks.${tier.key}${index}`)),
    position: `${(tier.at / 50) * 100}%`,
  }
}))

async function copyLink() {
  if (!data.value) return
  try {
    await navigator.clipboard.writeText(data.value.link)
    copied.value = true
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copied.value = false }, 2400)
    actionToasts.notify(t('referrals.copiedToast'), 'success')
  } catch {
    actionToasts.notify(data.value.link, 'success')
  }
}

async function share() {
  if (!data.value) return
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title: 'Wave', text: t('referrals.shareText'), url: data.value.link })
    } catch { /* Closing the share sheet is not an error. */ }
    return
  }
  await copyLink()
}

function downloadQr() {
  if (!qr.value || !data.value) return
  const link = document.createElement('a')
  link.href = qr.value
  link.download = `wave-poziv-${data.value.code.toLowerCase()}.png`
  link.click()
}
</script>

<template>
  <AdminPage class="referrals-page">
    <div class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" />
      <main class="admin-dashboard__main referrals">
        <header class="referrals__heading">
          <p class="eyebrow">{{ t('referrals.eyebrow') }}</p>
          <h1>{{ t('referrals.title') }}</h1>
          <p>{{ t('referrals.intro') }}</p>
        </header>
        <LocalizedLink v-if="!currentUser" class="button button--dark" :to="{ name: 'login' }">{{ t('auth.signIn') }}</LocalizedLink>
        <StatusMessage v-else-if="!creator">{{ t('referrals.creatorsOnly') }}</StatusMessage>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <LoadingSkeleton v-if="loading && !data" variant="dashboard" :label="t('adminDashboard.loading')" />

        <template v-if="data">
          <section class="referrals-link" :aria-label="t('referrals.linkLabel')">
            <div class="referrals-link__main">
              <p class="referrals-link__label">{{ t('referrals.linkLabel') }}</p>
              <div class="referrals-link__field">
                <span class="referrals-link__url">{{ data.link.replace(/^https?:\/\//, '') }}</span>
                <button type="button" class="referrals-link__copy" @click="copyLink">
                  <Check v-if="copied" :size="17" aria-hidden="true" /><Copy v-else :size="17" aria-hidden="true" />{{ copied ? t('referrals.copied') : t('referrals.copy') }}
                </button>
              </div>
              <div class="referrals-link__actions">
                <span class="referrals-link__code">{{ t('referrals.codeLabel') }}: <strong>{{ data.code }}</strong></span>
                <button type="button" class="referrals-link__ghost" @click="share"><Share2 :size="16" aria-hidden="true" />{{ t('referrals.share') }}</button>
                <button type="button" class="referrals-link__ghost" :disabled="!qr" @click="downloadQr"><Download :size="16" aria-hidden="true" />{{ t('referrals.qrDownload') }}</button>
                <button type="button" class="referrals-link__ghost referrals-link__qr-toggle" :aria-expanded="qrOpen" @click="qrOpen = !qrOpen"><QrCode :size="16" aria-hidden="true" />{{ t('referrals.qrShow') }}</button>
              </div>
              <p class="referrals-link__note">{{ t('referrals.linkNote') }}</p>
            </div>
            <div v-if="qr" class="referrals-link__qr" :class="{ 'is-open': qrOpen }"><img :src="qr" :alt="t('referrals.qrAlt', { link: data.link })" width="190" height="190" /></div>
          </section>

          <section class="referrals-card referrals-progress" aria-labelledby="referrals-progress-title">
            <div class="referrals-progress__top">
              <div>
                <h2 id="referrals-progress-title">{{ t('referrals.counted') }}</h2>
                <p class="referrals-progress__count">{{ counted }}<span> / 50</span></p>
              </div>
              <p class="referrals-progress__next">{{ nextText }}</p>
            </div>
            <div class="referrals-progress__bar" role="progressbar" :aria-valuenow="counted" aria-valuemin="0" aria-valuemax="50" :aria-label="t('referrals.counted')">
              <span class="referrals-progress__fill" :style="{ width: fill }"></span>
              <span v-for="tier in tiers" :key="tier.at" class="referrals-progress__marker" :class="[`referrals-progress__marker--${tier.key}`, { 'is-won': counted >= tier.at }]" :style="{ left: tier.position }"><i aria-hidden="true"></i><b>{{ tier.at }}</b></span>
            </div>
            <p class="referrals-progress__pending">{{ t('referrals.pendingNote', { count: data.pending }) }}</p>
          </section>

          <section class="referrals-tiers" aria-labelledby="referrals-tiers-title">
            <h2 id="referrals-tiers-title" class="referrals-section-title">{{ t('referrals.tiersTitle') }}</h2>
            <div class="referrals-tiers__grid">
              <article v-for="tier in tiers" :key="tier.at" class="referrals-tier" :class="[`referrals-tier--${tier.key}`, `is-${tier.state}`]">
                <div class="referrals-tier__head">
                  <span class="referrals-tier__medal" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2.9 6 6.6.8-4.9 4.5 1.3 6.5L12 17l-5.9 3.3 1.3-6.5L2.5 9.3l6.6-.8z" /></svg></span>
                  <div><small>{{ t('referrals.tierAt', { count: tier.at }) }}</small><h3>{{ t(`referrals.tiers.${tier.key}`) }}</h3></div>
                </div>
                <ul><li v-for="perk in tier.perks" :key="perk"><Check :size="17" aria-hidden="true" />{{ perk }}</li></ul>
                <p class="referrals-tier__state">{{ tier.stateLabel }}</p>
              </article>
            </div>
            <p class="referrals-note">{{ t('referrals.creditsNote') }}</p>
          </section>

          <div class="referrals-bottom">
            <section class="referrals-card referrals-list" aria-labelledby="referrals-list-title">
              <header><h2 id="referrals-list-title">{{ t('referrals.invitedTitle') }}</h2><span>{{ t('referrals.total', { count: data.total }) }}</span></header>
              <p v-if="!data.referrals.length" class="referrals-list__empty">{{ t('referrals.empty') }}</p>
              <div v-for="item in data.referrals" :key="item.id" class="referrals-list__row">
                <span class="referrals-list__avatar" aria-hidden="true">{{ (item.name || '?').split(/\s+/).map(word => word[0]).join('').slice(0, 2).toUpperCase() }}</span>
                <span class="referrals-list__text"><strong>{{ item.name || t('referrals.unnamed') }}</strong><small>{{ formatDate(item.createdAt) }}</small></span>
                <span class="referrals-list__status" :class="`is-${item.status}`"><i aria-hidden="true"></i>{{ t(`referrals.status.${item.status}`) }}</span>
              </div>
            </section>

            <section class="referrals-card referrals-rules" aria-labelledby="referrals-rules-title">
              <h2 id="referrals-rules-title">{{ t('referrals.rulesTitle') }}</h2>
              <ol>
                <li><span>1</span>{{ t('referrals.rule1', { code: data.code }) }}</li>
                <li><span>2</span>{{ t('referrals.rule2') }}</li>
                <li><span>3</span>{{ t('referrals.rule3') }}</li>
              </ol>
              <p class="referrals-rules__warning">{{ t('referrals.fraud') }}</p>
            </section>
          </div>
        </template>
      </main>
    </div>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AccountReferralsView.scss"></style>
