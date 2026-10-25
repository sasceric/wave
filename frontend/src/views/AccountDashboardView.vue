<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, ClipboardCheck, Coins, Handshake, Mail, Megaphone, MessageCircle, Pencil, Search, Settings } from '@lucide/vue'
import AccountActivationPanel from '../components/account/AccountActivationPanel.vue'
import AccountVisibilityCard from '../components/account/AccountVisibilityCard.vue'
import AccountPage from '../components/account/AccountPage.vue'
import ProfileStrengthCard from '../components/account/ProfileStrengthCard.vue'
import CreatorProfileStatistics from '../components/account/CreatorProfileStatistics.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import RatingSummary from '../components/reviews/RatingSummary.vue'
import { currentUser, loadCurrentUser } from '../composables/useCurrentUser'
import { unreadMessageCount } from '../composables/useUnreadMessages'
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
function metricLink(key) {
  const status = creator.value ? { invitations: 'pending', offers: 'offered', active: 'hired', applications: 'all' }[key] : key === 'campaigns' ? 'open' : 'all'
  return { name: creator.value ? 'account-applications' : 'account-campaigns', query: { status } }
}
// "Next up": concrete things waiting for this account, most urgent first.
const todos = computed(() => {
  const counts = snapshot.value?.counts || {}
  const items = creator.value ? [
    { key: 'offers', count: counts.offers, icon: Coins, tone: 'offer', to: metricLink('offers') },
    { key: 'invitations', count: counts.invitations, icon: Mail, tone: 'invite', to: metricLink('invitations') },
    { key: 'active', count: counts.active, icon: Handshake, tone: 'work', to: metricLink('active') },
  ] : [
    { key: 'applications', count: counts.applications, icon: ClipboardCheck, tone: 'offer', to: metricLink('applications') },
    { key: 'offers', count: counts.offers, icon: Mail, tone: 'invite', to: metricLink('offers') },
    { key: 'active', count: counts.active, icon: Handshake, tone: 'work', to: metricLink('active') },
  ]
  items.push({ key: 'messages', count: unreadMessageCount.value, icon: MessageCircle, tone: 'work', to: { name: 'messages' } })
  return items.filter((item) => item.count > 0).map((item) => ({ ...item, prefix: creator.value || item.key === 'messages' ? 'todoCreator' : 'todoCompany' }))
})
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
      <div v-if="ready" class="personal-overview__heading-actions">
        <LocalizedLink class="button button--tonal" :to="{ name: 'account' }">
          <Pencil :size="15" aria-hidden="true" />{{ t('personalDashboard.editProfile') }}
        </LocalizedLink>
        <LocalizedLink class="button button--dark" :to="{ name: creator ? 'campaigns' : 'account-campaign-create' }">
          <component :is="creator ? Search : Megaphone" :size="16" aria-hidden="true" />{{ t(creator ? 'personalDashboard.findCampaign' : 'personalDashboard.newCampaign') }}
        </LocalizedLink>
      </div>
    </header>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <LocalizedLink v-if="!currentUser" class="button button--dark" :to="{ name: 'login' }">{{ t('auth.signIn') }}</LocalizedLink>
    <p v-else-if="loading && !snapshot" role="status">{{ t('activation.loading') }}</p>
    <template v-else-if="snapshot">
      <div v-if="!ready" class="activation-layout"><AccountActivationPanel :user="currentUser" /><AccountVisibilityCard /></div>
      <div v-else class="personal-overview__body">
        <div class="personal-overview__main">
          <ProfileStrengthCard />
          <section v-if="todos.length" class="personal-overview__section" aria-labelledby="overview-todo-title">
            <header class="personal-overview__section-head"><div><h2 id="overview-todo-title">{{ t('accountHub.todoTitle') }}</h2><p>{{ t('accountHub.todoIntro') }}</p></div></header>
            <ul class="personal-overview__todo">
              <li v-for="item in todos" :key="item.key">
                <span class="personal-overview__todo-count" :class="`personal-overview__todo-count--${item.tone}`"><component :is="item.icon" :size="16" aria-hidden="true" />{{ item.count > 99 ? '99+' : item.count }}</span>
                <div><strong>{{ t(`accountHub.${item.prefix}.${item.key}`) }}</strong><span>{{ t(`accountHub.${item.prefix}.${item.key}Hint`) }}</span></div>
                <LocalizedLink class="button button--tonal" :to="item.to">{{ t(`accountHub.${item.prefix}.${item.key}Action`) }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
              </li>
            </ul>
          </section>
          <section v-else class="activation-spotlight"><div><p>{{ t('activation.next') }}</p><h2>{{ t(creator ? (snapshot.counts.applications > 0 ? 'activation.creatorContinue' : 'activation.creatorReady') : 'activation.companyReady') }}</h2><p>{{ t(creator ? 'activation.creatorReadyHint' : 'activation.companyReadyHint') }}</p></div><LocalizedLink class="button" :to="{ name: creator ? 'campaigns' : 'account-campaign-create' }">{{ t(creator ? 'personalDashboard.findCampaign' : 'personalDashboard.newCampaign') }}<ArrowRight :size="17" aria-hidden="true" /></LocalizedLink></section>

          <section class="personal-overview__section" aria-labelledby="overview-pipeline-title">
            <header class="personal-overview__section-head">
              <div><h2 id="overview-pipeline-title">{{ t(creator ? 'accountHub.work' : !creator && currentUser.canOfferServices ? 'agency.clientWork' : 'accountHub.campaigns') }}</h2></div>
              <LocalizedLink :to="{ name: creator ? 'account-applications' : 'account-campaigns' }">{{ t('personalDashboard.all') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            </header>
            <div class="personal-overview__metrics">
              <LocalizedLink v-for="metric in metrics" :key="metric.key" class="personal-overview__metric" :to="metricLink(metric.key)">
                <strong>{{ metric.count }}</strong>
                <span>{{ t(`personalDashboard.${creator ? 'creator' : 'company'}.${metric.key}`) }}</span>
                <small>{{ t(`personalDashboard.${creator ? 'creator' : 'company'}.${metric.key}Hint`) }}</small>
              </LocalizedLink>
            </div>
          </section>

          <section v-if="currentUser.canOfferServices && !creator || currentUser.hasServiceHistory && !creator" class="personal-overview__section" aria-labelledby="overview-provider-title">
            <header class="personal-overview__section-head"><div><h2 id="overview-provider-title">{{ t('agency.providerWork') }}</h2></div></header>
            <div class="personal-overview__metrics">
              <LocalizedLink v-for="key in ['invitations', 'applications', 'offers', 'active']" :key="key" class="personal-overview__metric" :to="{ name: 'account-applications', query: { status: key === 'invitations' ? 'pending' : key === 'offers' ? 'offered' : key === 'active' ? 'hired' : 'all' } }">
                <strong>{{ snapshot.serviceCounts?.[key] || 0 }}</strong><span>{{ t(`personalDashboard.creator.${key}`) }}</span><small>{{ t(`personalDashboard.creator.${key}Hint`) }}</small>
              </LocalizedLink>
            </div>
          </section>

          <section class="personal-overview__section personal-overview__activity" aria-labelledby="overview-recent-title">
            <header class="personal-overview__section-head">
              <div><h2 id="overview-recent-title">{{ t('personalDashboard.recent') }}</h2><p>{{ t('personalDashboard.recentHint') }}</p></div>
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
          <CreatorProfileStatistics v-if="creator" />
        </div>
        <aside class="personal-overview__aside">
          <AccountVisibilityCard />
          <section class="personal-overview__stats" :aria-label="t(creator ? 'reviews.earnings' : 'reviews.spending')">
            <dl>
              <div>
                <dt>{{ t(creator ? 'reviews.earnings' : 'reviews.spending') }}</dt>
                <dd><template v-if="snapshot.financial?.totals?.length"><span v-for="total in snapshot.financial.totals" :key="total.currency">{{ formatMoney(total.amount, total.currency) }}</span></template><span v-else>—</span></dd>
              </div>
              <div v-if="currentUser.canOfferServices && !creator">
                <dt>{{ t('reviews.earnings') }}</dt>
                <dd><span v-for="total in snapshot.financial?.serviceTotals || []" :key="total.currency">{{ formatMoney(total.amount, total.currency) }}</span><span v-if="!snapshot.financial?.serviceTotals?.length">—</span></dd>
              </div>
              <div>
                <dt>{{ t('personalDashboard.rating') }}</dt>
                <dd><LocalizedLink :to="{ name: 'account-reviews' }"><RatingSummary :rating="snapshot.financial?.rating" compact /></LocalizedLink></dd>
              </div>
              <div>
                <dt>{{ t('credits.title') }}</dt>
                <dd><LocalizedLink :to="{ name: 'account-credits' }">{{ snapshot.credits?.unlimited ? '∞' : snapshot.credits?.balance ?? 0 }}<ArrowRight :size="14" aria-hidden="true" /></LocalizedLink></dd>
              </div>
            </dl>
            <p>{{ t('personalDashboard.financialHint') }}</p>
          </section>
          <LocalizedLink class="personal-overview__settings-link" :to="{ name: 'account-settings' }"><Settings :size="16" aria-hidden="true" />{{ t('account.sidebarSettings') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
        </aside>
      </div>
    </template>
  </AccountPage>
</template>
<style lang="scss" src="../scss/views/AccountDashboardView.scss"></style>
