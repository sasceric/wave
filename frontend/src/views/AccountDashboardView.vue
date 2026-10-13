<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Check, ClipboardCheck, Coins, Handshake, Mail, Megaphone, Pencil, ShieldCheck } from '@lucide/vue'
import AccountPage from '../components/account/AccountPage.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import RatingSummary from '../components/reviews/RatingSummary.vue'
import { currentUser, loadCurrentUser } from '../composables/useCurrentUser'
import { apiGet, formatDate, formatMoney } from '../lib/api'
import { campaignActivityStage } from '../lib/campaignActivity'
const { t, locale } = useI18n()
const snapshot = ref(null)
const loading = ref(false)
const error = ref('')
let revision = 0
const creator = computed(() => currentUser.value?.accountType === 'creator')
const name = computed(() => currentUser.value?.profile?.displayName || currentUser.value?.profile?.name || currentUser.value?.name || '')
const ready = computed(() => snapshot.value?.ready === true)
const metrics = computed(() => (creator.value ? [
  ['invitations', Mail], ['offers', ClipboardCheck], ['active', Handshake], ['applications', Megaphone],
] : [['campaigns', Megaphone], ['applications', ClipboardCheck], ['offers', Mail], ['active', Handshake]]).map(([key, icon]) => ({ key, icon, count: snapshot.value?.counts?.[key] || 0 })))
const checks = computed(() => [
  { key: 'profile', done: currentUser.value?.profileComplete === true },
  { key: 'email', done: currentUser.value?.emailVerified === true },
  { key: 'approval', done: currentUser.value?.approved === true },
])
function metricLink(key) {
  const status = creator.value ? { invitations: 'pending', offers: 'offered', active: 'hired', applications: 'all' }[key] : key === 'campaigns' ? 'open' : 'all'
  return { name: creator.value ? 'account-applications' : 'account-campaigns', query: { status } }
}
function activityLink(row) {
  return creator.value ? { name: 'account-applications', query: { q: row.title } } : { name: 'account-campaign-detail', params: { slug: row.slug } }
}
function stage(row) {
  return creator.value ? campaignActivityStage({ status: row.status === 'invited' ? 'pending' : row.status, source: row.source || (row.status === 'invited' ? 'invitation' : 'application'), offer: row.offer_status ? { status: row.offer_status } : null, campaign: { status: row.campaign_status, closesAt: row.closes_at } }) : row.campaign_status
}
function statusLabel(row) {
  const value = stage(row)
  const labels = { applied: 'hiring.applied', invited: 'hiring.invited', discussing: 'hiring.discussing', offered: 'hiring.offerWaiting', hired: 'hiring.hired', finished: 'account.finished', cancelled: 'account.cancelled', rejected: 'account.rejected', closed: 'campaignChat.closed' }
  return t(creator.value ? labels[value] : `account.${value}`)
}
async function load() {
  const generation = ++revision
  const id = currentUser.value?.id
  snapshot.value = null; error.value = ''
  if (!id) { loading.value = false; return }
  loading.value = true
  try {
    const result = await apiGet('/me/dashboard')
    if (generation !== revision || id !== currentUser.value?.id) return
    if (result.userId !== id) { await loadCurrentUser(); return }
    snapshot.value = result.data
  } catch (cause) { if (generation === revision) error.value = cause.message }
  finally { if (generation === revision) loading.value = false }
}
watch(() => [currentUser.value?.id, currentUser.value?.profileComplete, currentUser.value?.approved, currentUser.value?.emailVerified, locale.value], load, { immediate: true })
onBeforeUnmount(() => { ++revision })
</script>
<template>
  <AccountPage class="personal-overview" :aria-busy="loading">
    <AccountSidebar v-if="currentUser" :user="currentUser" />
    <header class="personal-overview__heading">
      <div>
        <p class="personal-overview__eyebrow">{{ t('personalDashboard.greeting', { name }) }}</p>
        <h1>{{ t('personalDashboard.title') }}</h1>
        <p>{{ t(`personalDashboard.${creator ? 'creatorIntro' : 'companyIntro'}`) }}</p>
      </div>
      <LocalizedLink class="button button--outline" :to="{ name: 'account' }">
        <Pencil :size="15" aria-hidden="true" />{{ t('personalDashboard.editProfile') }}
      </LocalizedLink>
    </header>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <LocalizedLink v-if="!currentUser" class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">{{ t('app.login') }}</LocalizedLink>
    <p v-else-if="loading && !snapshot" role="status">{{ t('adminDashboard.loading') }}</p>
    <template v-else-if="snapshot">
      <section v-if="!ready" class="personal-overview__setup">
        <ShieldCheck :size="30" aria-hidden="true" />
        <div><h2>{{ t('personalDashboard.setupTitle') }}</h2><p>{{ t('personalDashboard.setupHint') }}</p></div>
        <ol>
          <li v-for="item in checks" :key="item.key">
            <span :class="{ 'is-done': item.done }">
              <Check v-if="item.done" :size="15" aria-hidden="true" />
              {{ t(`personalDashboard.checks.${item.key}`) }}
              <small>{{ t(item.done ? 'personalDashboard.done' : 'personalDashboard.pending') }}</small>
            </span>
          </li>
        </ol>
        <LocalizedLink class="button button--outline" :to="{ name: 'account' }">
          {{ t('personalDashboard.editProfile') }}<ArrowRight :size="16" aria-hidden="true" />
        </LocalizedLink>
      </section>
      <template v-else>
        <div class="personal-overview__metrics">
          <LocalizedLink v-for="metric in metrics" :key="metric.key" class="personal-overview__metric" :to="metricLink(metric.key)">
            <span><component :is="metric.icon" :size="18" aria-hidden="true" />{{ t(`personalDashboard.${creator ? 'creator' : 'company'}.${metric.key}`) }}</span>
            <strong>{{ metric.count }}</strong>
            <small>{{ t(`personalDashboard.${creator ? 'creator' : 'company'}.${metric.key}Hint`) }}<ArrowRight :size="14" aria-hidden="true" /></small>
          </LocalizedLink>
        </div>
        <div class="personal-overview__body">
          <section class="personal-overview__activity">
            <header>
              <div><h2>{{ t('personalDashboard.recent') }}</h2><p>{{ t('personalDashboard.recentHint') }}</p></div>
              <LocalizedLink :to="{ name: creator ? 'account-applications' : 'account-campaigns' }">{{ t('personalDashboard.all') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            </header>
            <p v-if="!snapshot.recent.length" class="personal-overview__empty">{{ t('personalDashboard.empty') }}</p>
            <ul v-else>
              <li v-for="row in snapshot.recent" :key="row.campaign_id">
                <LocalizedLink :to="activityLink(row)">
                  <span><strong>{{ row.title }}</strong><small>{{ creator ? row.company_name : formatDate(row.created_at) }}</small></span>
                  <span class="personal-overview__status" :class="`personal-overview__status--${stage(row)}`">{{ statusLabel(row) }}</span>
                  <ArrowRight :size="16" aria-hidden="true" />
                </LocalizedLink>
              </li>
            </ul>
          </section>
          <aside class="personal-overview__aside">
            <section class="personal-overview__financial">
              <p>{{ t(creator ? 'reviews.earnings' : 'reviews.spending') }}</p>
              <template v-if="snapshot.financial?.totals?.length">
                <strong v-for="total in snapshot.financial.totals" :key="total.currency">{{ formatMoney(total.amount, total.currency) }}</strong>
              </template>
              <strong v-else>—</strong>
              <small>{{ t('personalDashboard.financialHint') }}</small>
            </section>
            <LocalizedLink class="personal-overview__credits" :to="{ name: 'account-credits' }">
              <span><Coins :size="17" aria-hidden="true" />{{ t('credits.title') }}</span>
              <strong>{{ snapshot.credits?.unlimited ? '∞' : snapshot.credits?.balance ?? 0 }}</strong>
              <small>{{ t(snapshot.credits?.unlimited ? 'credits.unlimited' : 'credits.balance') }}<ArrowRight :size="15" aria-hidden="true" /></small>
            </LocalizedLink>
            <section class="personal-overview__rating">
              <h2>{{ t('personalDashboard.rating') }}</h2>
              <RatingSummary :rating="snapshot.financial?.rating" />
              <LocalizedLink :to="{ name: 'account-reviews' }">{{ t('personalDashboard.reviews') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            </section>
            <LocalizedLink class="button button--dark" :to="{ name: creator ? 'campaigns' : 'account-campaign-create' }">
              <Megaphone :size="17" aria-hidden="true" />{{ t(creator ? 'personalDashboard.findCampaign' : 'personalDashboard.newCampaign') }}
            </LocalizedLink>
          </aside>
        </div>
      </template>
    </template>
  </AccountPage>
</template>
<style lang="scss" src="../scss/views/AccountDashboardView.scss"></style>
