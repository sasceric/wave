<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowUpRight, CalendarDays, Check, ClipboardCheck, Flag, LifeBuoy, Megaphone, Send, Users, Building2, UserRoundCheck, Activity, Share2, Images, Package } from '@lucide/vue'
import SingleSelect from '../shared/SingleSelect.vue'
import AdminChart from './AdminChart.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import LoadingSkeleton from '../shared/LoadingSkeleton.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { currentUser, loadCurrentUser } from '../../composables/useCurrentUser'
import { apiGet, formatDate } from '../../lib/api'
import { bucketOverviewSeries, cumulativeOverviewSeries } from '../../lib/overviewCharts'

const props = defineProps({ metrics: { type: Object, default: () => ({}) } })
const { t, locale } = useI18n()
const chartId = useId()
const days = ref(30)
const accountType = ref('all')
const activationTypes = computed(() => ['all', 'creator', 'company', 'brand', 'agency'].map(value => ({ value, label: t(`activation.filter_${value}`) })))
const activationRows = computed(() => {
  const counts = statistics.value?.activation?.counts || {}
  let previous = 0
  return ['registered', 'verified', 'completed', 'approved', 'first_action'].map((key, index) => {
    const count = counts[key] || 0
    const row = { key, count, width: counts.registered ? count / counts.registered * 100 : 0, rate: index > 0 && previous ? Math.round(count / previous * 100) : null }
    previous = count
    return row
  })
})
const statistics = ref(null)
const profileCompletionRows = computed(() => {
  const counts = statistics.value?.profileCompletion || {}
  return [['networks', Share2], ['works', Images], ['packages', Package], ['complete', Check]].map(([key, icon]) => ({
    key, icon, count: counts[key] || 0, rate: counts.total ? Math.round((counts[key] || 0) / counts.total * 100) : 0,
  }))
})
const agencyProfileCompletionRows = computed(() => {
  const counts = statistics.value?.agencyProfileCompletion || {}
  return [['services', ClipboardCheck], ['works', Images], ['packages', Package], ['complete', Check]].map(([key, icon]) => ({
    key, icon, count: counts[key] || 0, rate: counts.total ? Math.round((counts[key] || 0) / counts.total * 100) : 0,
  }))
})
const completionSections = computed(() => [
  { key: 'creator', title: 'profileCompletionTitle', hint: 'profileCompletionHint', total: statistics.value?.profileCompletion?.total || 0, rows: profileCompletionRows.value },
  { key: 'agency', title: 'agencyCompletionTitle', hint: 'agencyCompletionHint', total: statistics.value?.agencyProfileCompletion?.total || 0, rows: agencyProfileCompletionRows.value },
])
const loading = ref(false)
const error = ref('')
let generation = 0
let disposed = false
async function load() {
  const version = ++generation
  const userId = currentUser.value?.id
  statistics.value = null
  error.value = ''
  if (!currentUser.value?.isAdmin) { loading.value = false; return }
  loading.value = true
  try {
    const response = await apiGet(`/admin/overview?days=${days.value}&accountType=${accountType.value}`)
    if (disposed || version !== generation || currentUser.value?.id !== userId) return
    if (response.userId !== userId) { await loadCurrentUser(); return }
    statistics.value = response.data
  } catch (cause) {
    if (!disposed && version === generation) error.value = cause.message
  } finally {
    if (version === generation) loading.value = false
  }
}
watch([days, accountType, locale, () => currentUser.value?.id, () => currentUser.value?.isAdmin], load, { immediate: true })
onMounted(() => window.addEventListener('wave:admin-counts-changed', load))
onBeforeUnmount(() => {
  disposed = true
  generation += 1
  window.removeEventListener('wave:admin-counts-changed', load)
})
const metricCards = computed(() => [
  { key: 'creators', icon: Users, route: 'admin-creators', tone: 'forest' },
  { key: 'companies', icon: Building2, route: 'admin-companies', tone: 'coral' },
  { key: 'campaigns', icon: Megaphone, route: 'admin-campaigns', tone: 'secondary' },
  { key: 'users', icon: UserRoundCheck, tone: 'surface' },
])
const attention = [
  { key: 'approvals', label: 'adminDashboard.navRegistrations', route: 'admin-registrations', icon: ClipboardCheck },
  { key: 'reports', label: 'overview.reports', route: 'admin-reports', icon: Flag },
  { key: 'tickets', label: 'overview.tickets', route: 'admin-support', icon: LifeBuoy },
]
const activity = [
  { key: 'applications', icon: ClipboardCheck }, { key: 'invitations', icon: Send },
  { key: 'hires', icon: Users }, { key: 'completed', icon: Check },
]
const buckets = computed(() => bucketOverviewSeries(statistics.value?.series || []))
const growth = computed(() => cumulativeOverviewSeries(statistics.value?.series || []))
function shortDate(date) { return new Intl.DateTimeFormat(locale.value === 'cnr' ? 'sr-Latn-ME' : locale.value, { day: '2-digit', month: '2-digit', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`)) }
const activityChart = computed(() => ({
  labels: buckets.value.map(row => `${shortDate(row.from)} — ${shortDate(row.to)}`),
  datasets: [
    { label: t('overview.applications'), data: buckets.value.map(row => row.applications), backgroundColor: 'var(--forest)', borderRadius: 5, maxBarThickness: 24 },
    { label: t('overview.invitations'), data: buckets.value.map(row => row.invitations), backgroundColor: 'var(--secondary)', borderRadius: 5, maxBarThickness: 24 },
  ],
}))
const growthChart = computed(() => ({
  labels: growth.value.map(row => shortDate(row.date)),
  datasets: [
    { label: t('overview.creators'), data: growth.value.map(row => row.creators), borderColor: 'var(--forest)', backgroundColor: '#173d3612', fill: true, tension: 0.15, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHitRadius: 12, borderWidth: 2 },
    { label: t('overview.campaigns'), data: growth.value.map(row => row.campaigns), borderColor: 'var(--coral)', borderDash: [4, 3], tension: 0.15, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHitRadius: 12, borderWidth: 2 },
  ],
}))
const statusColors = { open: 'var(--forest)', closed: 'var(--secondary)', finished: 'var(--coral)', cancelled: 'var(--surface)' }
const statusRows = computed(() => Object.entries(statistics.value?.campaignStatuses || {}).map(([key, count]) => ({ key, count, color: statusColors[key] })))
const campaignTotal = computed(() => statusRows.value.reduce((total, row) => total + row.count, 0))
const statusChart = computed(() => ({ labels: statusRows.value.map(row => t(`account.${row.key}`)), datasets: [{ data: statusRows.value.map(row => row.count), backgroundColor: statusRows.value.map(row => row.color), borderWidth: 0, spacing: 3, hoverOffset: 4 }] }))
const attentionTotal = computed(() => Object.values(statistics.value?.attention || {}).reduce((total, count) => total + count, 0))
</script>

<template>
  <section class="wave-overview" :aria-busy="loading" :aria-label="t('overview.title')">
    <header class="wave-overview__toolbar">
      <p>{{ t('overview.dashboardHint') }}</p>
      <div class="wave-overview__controls">
        <span v-if="statistics" class="wave-overview__range"><CalendarDays :size="14" aria-hidden="true" />{{ formatDate(statistics.from) }} — {{ formatDate(statistics.to) }}</span>
        <div class="wave-overview__periods" role="group" :aria-label="t('overview.period')">
          <button v-for="period in [7, 30, 90]" :key="period" type="button" :aria-pressed="days === period" :disabled="loading" @click="days = period">{{ t('overview.days', { count: period }) }}</button>
        </div>
      </div>
    </header>
    <div class="wave-overview__metrics">
      <component :is="card.route ? LocalizedLink : 'article'" v-for="card in metricCards" :key="card.key" class="wave-overview__metric" :to="card.route ? { name: card.route } : undefined">
        <div class="wave-overview__metric-top"><span class="wave-overview__icon" :class="`wave-overview__icon--${card.tone}`"><component :is="card.icon" :size="19" aria-hidden="true" /></span><ArrowUpRight v-if="card.route" :size="16" aria-hidden="true" /></div>
        <span class="wave-overview__metric-label">{{ t(`adminDashboard.${card.key}`) }}</span>
        <strong>{{ props.metrics[card.key] ?? 0 }}</strong>
        <small>{{ t('overview.platformTotal') }}</small>
      </component>
    </div>
    <StatusMessage v-if="error" variant="error">{{ error }} <button type="button" class="text-link" @click="load">{{ t('reviews.retry') }}</button></StatusMessage>
    <LoadingSkeleton v-else-if="loading && !statistics" variant="dashboard" :label="t('adminDashboard.loading')" />
    <template v-if="statistics">
      <section class="activation-funnel">
        <header><div><span class="wave-overview__eyebrow">{{ t('activation.funnelEyebrow') }}</span><h2>{{ t('activation.funnelTitle') }}</h2><p>{{ t('activation.funnelHint') }}</p></div><SingleSelect v-model="accountType" :options="activationTypes" :label="t('adminDashboard.accountType')" :show-label="false" /></header>
        <div v-if="accountType !== 'creator'" class="activation-funnel__companies">
          <p>{{ t('activation.companyBreakdownHint') }}</p>
          <div role="group" :aria-label="t('activation.companyBreakdownLabel')">
            <button v-for="kind in ['brand', 'agency']" :key="kind" type="button" :aria-pressed="accountType === kind" @click="accountType = kind">
              <span>{{ t(`activation.filter_${kind}`) }}</span><strong>{{ statistics.activation?.breakdown?.[kind] || 0 }}</strong>
            </button>
          </div>
        </div>
        <ol><li v-for="row in activationRows" :key="row.key"><div><span>{{ t(`activation.stage_${row.key}`) }}</span><strong>{{ row.count }}</strong><small>{{ row.rate === null ? '—' : `${row.rate}%` }}</small></div><span class="activation-funnel__track"><i :style="{ width: `${row.width}%` }"></i></span></li></ol>
        <p class="activation-funnel__note">{{ t('activation.funnelNote') }}<template v-if="statistics.activation?.measuredSince"> · {{ formatDate(statistics.activation.measuredSince) }}</template></p>
      </section>
      <section v-for="section in completionSections" :key="section.key" class="profile-completion" :aria-labelledby="`${chartId}-completion-${section.key}`">
        <header>
          <span class="wave-overview__eyebrow">{{ t('activation.profileCompletionEyebrow') }}</span>
          <h2 :id="`${chartId}-completion-${section.key}`">{{ t(`activation.${section.title}`) }}</h2>
          <p>{{ t(`activation.${section.hint}`, { count: section.total }) }}</p>
        </header>
        <dl class="profile-completion__grid">
          <div v-for="row in section.rows" :key="row.key">
            <dt><component :is="row.icon" :size="18" aria-hidden="true" />{{ t(`activation.profileCompletion_${row.key}`) }}</dt>
            <dd>
              <div class="profile-completion__value"><strong>{{ row.count }}</strong><span>{{ row.rate }}%</span></div>
              <div class="profile-completion__track" aria-hidden="true"><span :style="{ width: `${row.rate}%` }"></span></div>
            </dd>
          </div>
        </dl>
        <p class="profile-completion__note">{{ t('activation.profileCompletionNote') }}</p>
      </section>
      <div class="wave-overview__grid">
        <article class="wave-overview__card wave-overview__activity">
          <header class="wave-overview__card-header"><div><span class="wave-overview__eyebrow">{{ t('overview.activity') }}</span><h2>{{ t('overview.applicationsAndInvites') }}</h2></div><span class="wave-overview__pill">{{ t('overview.days', { count: statistics.days }) }}</span></header>
          <div class="wave-overview__legend"><span><i class="is-forest"></i>{{ t('overview.applications') }}</span><span><i class="is-secondary"></i>{{ t('overview.invitations') }}</span></div>
          <AdminChart type="bar" :data="activityChart" :label="t('overview.activityChartDescription', { applications: statistics.totals.applications, invitations: statistics.totals.invitations })" />
          <div class="wave-overview__activity-summary"><div v-for="item in activity" :key="item.key"><span><component :is="item.icon" :size="13" aria-hidden="true" />{{ t(`overview.${item.key}`) }}</span><strong>{{ statistics.totals[item.key] }}</strong></div></div>
          <p class="wave-overview__footnote">{{ t('overview.hiresHint') }} · UTC</p>
          <details class="wave-overview__data"><summary>{{ t('overview.showData') }}</summary><div><table><caption class="sr-only">{{ t('overview.activity') }}</caption><thead><tr><th>{{ t('hiring.activityDate') }}</th><th>{{ t('overview.applications') }}</th><th>{{ t('overview.invitations') }}</th></tr></thead><tbody><tr v-for="day in statistics.series" :key="day.date"><td>{{ formatDate(day.date) }}</td><td>{{ day.applications }}</td><td>{{ day.invitations }}</td></tr></tbody></table></div></details>
        </article>
        <article class="wave-overview__card wave-overview__statuses">
          <header class="wave-overview__card-header"><div><span class="wave-overview__eyebrow">{{ t('overview.campaigns') }}</span><h2>{{ t('overview.statuses') }}</h2></div><Megaphone :size="18" aria-hidden="true" /></header>
          <div class="wave-overview__donut" role="img" :aria-label="statusRows.map(row => `${t(`account.${row.key}`)}: ${row.count}`).join(', ')">
            <AdminChart type="doughnut" :data="statusChart" :height="180" :label="statusRows.map(row => `${t(`account.${row.key}`)}: ${row.count}`).join(', ')" />
            <div><strong>{{ campaignTotal }}</strong><span>{{ t('adminDashboard.campaigns') }}</span></div>
          </div>
          <ul class="wave-overview__status-legend"><li v-for="row in statusRows" :key="row.key"><span><i :style="{ background: row.color }"></i>{{ t(`account.${row.key}`) }}</span><strong>{{ row.count }}</strong><small>{{ campaignTotal ? Math.round(row.count / campaignTotal * 100) : 0 }}%</small></li></ul>
          <p class="wave-overview__footnote">{{ t('overview.statusesHint') }}</p>
        </article>
        <article class="wave-overview__card wave-overview__growth">
          <header class="wave-overview__card-header"><div><span class="wave-overview__eyebrow">{{ t('overview.growth') }}</span><h2>{{ t('overview.growthTitle') }}</h2></div><Activity :size="18" aria-hidden="true" /></header>
          <div class="wave-overview__growth-totals"><span><i class="is-forest"></i><strong>{{ statistics.totals.creators }}</strong>{{ t('overview.creators') }}</span><span><i class="is-coral"></i><strong>{{ statistics.totals.campaigns }}</strong>{{ t('overview.campaigns') }}</span></div>
          <AdminChart type="line" :data="growthChart" :height="180" :label="t('overview.chartDescription', { creators: statistics.totals.creators, campaigns: statistics.totals.campaigns })" />
          <p class="wave-overview__footnote">{{ t('overview.cumulativeHint') }}</p>
          <details class="wave-overview__data"><summary>{{ t('overview.showData') }}</summary><div><table><caption class="sr-only">{{ t('overview.cumulativeHint') }}</caption><thead><tr><th>{{ t('hiring.activityDate') }}</th><th>{{ t('overview.creators') }}</th><th>{{ t('overview.campaigns') }}</th></tr></thead><tbody><tr v-for="day in growth" :key="day.date"><td>{{ formatDate(day.date) }}</td><td>{{ day.creators }}</td><td>{{ day.campaigns }}</td></tr></tbody></table></div></details>
        </article>
        <section class="wave-overview__attention" :aria-label="t('adminDashboard.needsAttention')">
          <header><span class="wave-overview__attention-icon"><ClipboardCheck :size="20" aria-hidden="true" /></span><span>{{ t('overview.adminWork') }}</span><strong>{{ attentionTotal }}</strong></header>
          <h2>{{ t('adminDashboard.needsAttention') }}</h2>
          <p>{{ t('overview.attentionHint') }}</p>
          <div><LocalizedLink v-for="item in attention" :key="item.key" :to="{ name: item.route }"><component :is="item.icon" :size="17" aria-hidden="true" /><span>{{ t(item.label) }}</span><strong>{{ statistics.attention[item.key] }}</strong><ArrowUpRight :size="15" aria-hidden="true" /></LocalizedLink></div>
          <small>{{ t('overview.currentWork') }}</small>
        </section>
        <section class="wave-overview__card wave-overview__recent">
          <header class="wave-overview__card-header"><div><span class="wave-overview__eyebrow">{{ t('overview.latest') }}</span><h2>{{ t('overview.recent') }}</h2></div><LocalizedLink class="wave-overview__view-all" :to="{ name: 'admin-campaigns' }">{{ t('overview.viewAll') }}<ArrowUpRight :size="14" aria-hidden="true" /></LocalizedLink></header>
          <p v-if="!statistics.recentCampaigns.length" class="wave-overview__footnote">{{ t('overview.empty') }}</p>
          <ul v-else><li v-for="campaign in statistics.recentCampaigns" :key="campaign.id"><span class="wave-overview__campaign-icon"><Megaphone :size="18" aria-hidden="true" /></span><LocalizedLink :to="{ name: 'admin-campaigns', query: { q: campaign.title } }">{{ campaign.title }}<small>{{ t('overview.published') }} {{ formatDate(campaign.published_at) }}</small></LocalizedLink><span class="wave-overview__status" :class="`wave-overview__status--${campaign.status}`"><i></i>{{ t(`account.${campaign.status}`) }}</span><LocalizedLink :to="{ name: 'admin-campaigns', query: { q: campaign.title } }" :aria-label="`${t('account.activityView')}: ${campaign.title}`" class="wave-overview__row-link"><ArrowUpRight :size="16" aria-hidden="true" /></LocalizedLink></li></ul>
        </section>
      </div>
    </template>
  </section>
</template>

<style lang="scss" src="../../scss/components/admin/AdminOverviewAnalytics.scss"></style>
