<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AdminPage from '../components/admin/AdminPage.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AmbassadorBadge from '../components/shared/AmbassadorBadge.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { currentUser } from '../composables/useCurrentUser'

// Admin → Preporuke: creators who invite others, tier rewards to activate, and flagged referrals.
const { t, locale } = useI18n()
const data = ref(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
let ticket = 0
const TIER = { 20: 'bronze', 35: 'silver', 50: 'gold' }

watch([locale, () => currentUser.value?.isAdmin], load, { immediate: true })

async function load() {
  if (!currentUser.value?.isAdmin) return
  const current = ++ticket
  loading.value = true
  error.value = ''
  try {
    const response = await apiGet('/admin/referrals')
    if (current === ticket) data.value = response.data
  } catch (cause) { if (current === ticket) error.value = cause.message }
  finally { if (current === ticket) loading.value = false }
}

const rows = computed(() => (data.value?.ambassadors || []).map((row) => {
  const approved = row.rewards.filter(reward => reward.approvedAt).map(reward => reward.tier)
  const tier = approved.length ? Math.max(...approved) : 0
  const next = [20, 35, 50].find(at => row.counted < at)
  return { ...row, tier, waiting: row.rewards.filter(reward => !reward.approvedAt), leftText: next ? t('referrals.admin.leftTo', { count: next - row.counted, tier: t(`referrals.tiers.${TIER[next]}`) }) : '' }
}))
const waitingCount = computed(() => rows.value.reduce((sum, row) => sum + row.waiting.length, 0))

async function run(path, body) {
  if (busy.value) return
  busy.value = true
  try {
    const response = await apiRequest(path, { method: 'POST', body })
    data.value = response.data
  } catch { /* The toast shows the reason. */ }
  finally { busy.value = false }
}

const approve = reward => run(`/admin/referral-rewards/${reward.id}/approve`, {})
const decide = (item, decision) => run(`/admin/referrals/${item.id}/decision`, { decision })
</script>

<template>
  <AdminPage>
    <div v-if="currentUser?.isAdmin" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" admin-layout />
      <main class="admin-dashboard__main referrals-admin">
        <header class="admin-dashboard__heading referrals-admin__heading">
          <div><h1>{{ t('referrals.adminTitle') }}</h1><p>{{ t('referrals.admin.intro') }}</p></div>
          <div v-if="data" class="referrals-admin__counts">
            <span><strong>{{ waitingCount }}</strong> {{ t('referrals.admin.rewardsWaiting') }}</span>
            <span class="is-warning"><strong>{{ data.review.length }}</strong> {{ t('referrals.admin.flagged') }}</span>
          </div>
        </header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <LoadingSkeleton v-if="loading && !data" variant="list" :count="4" :label="t('adminDashboard.loading')" />

        <template v-if="data">
          <section class="referrals-admin__card" aria-labelledby="referrals-admin-ambassadors">
            <h2 id="referrals-admin-ambassadors">{{ t('referrals.admin.ambassadors') }}</h2>
            <p v-if="!rows.length" class="referrals-admin__empty">{{ t('referrals.admin.empty') }}</p>
            <div v-else class="referrals-admin__table" role="table" :aria-label="t('referrals.admin.ambassadors')">
              <div class="referrals-admin__row referrals-admin__row--head" role="row">
                <span role="columnheader">{{ t('referrals.admin.creator') }}</span>
                <span role="columnheader">{{ t('referrals.admin.counted') }}</span>
                <span role="columnheader">{{ t('referrals.admin.pending') }}</span>
                <span role="columnheader">{{ t('referrals.admin.tier') }}</span>
                <span role="columnheader">{{ t('referrals.admin.reward') }}</span>
              </div>
              <div v-for="row in rows" :key="row.userId" class="referrals-admin__row" role="row">
                <span role="cell" class="referrals-admin__who">
                  <LocalizedLink v-if="row.slug" :to="{ name: 'creator-profile', params: { slug: row.slug } }">{{ row.name }}</LocalizedLink>
                  <strong v-else>{{ row.name }}</strong>
                  <small>{{ t('referrals.codeLabel') }} {{ row.code }} · {{ row.email }}</small>
                </span>
                <span role="cell" class="referrals-admin__number">{{ row.counted }}</span>
                <span role="cell">{{ row.pending }}<small v-if="row.rejected"> · {{ t('referrals.admin.rejectedCount', { count: row.rejected }) }}</small></span>
                <span role="cell"><AmbassadorBadge v-if="row.tier" :tier="row.tier" /><span v-else class="referrals-admin__muted">—</span></span>
                <span role="cell" class="referrals-admin__actions">
                  <button v-for="reward in row.waiting" :key="reward.id" type="button" class="button button--dark button--small" :disabled="busy" @click="approve(reward)">{{ t('referrals.admin.approve', { tier: t(`referrals.tiers.${reward.key}`) }) }}</button>
                  <span v-if="!row.waiting.length && row.tier" class="referrals-admin__active"><i aria-hidden="true"></i>{{ t('referrals.admin.active') }}</span>
                  <span v-if="!row.waiting.length && row.leftText" class="referrals-admin__muted">{{ row.leftText }}</span>
                </span>
              </div>
            </div>
            <p class="referrals-admin__note">{{ t('referrals.admin.manual') }}</p>
          </section>

          <section class="referrals-admin__card" aria-labelledby="referrals-admin-review">
            <h2 id="referrals-admin-review">{{ t('referrals.admin.reviewTitle') }}</h2>
            <p class="referrals-admin__intro">{{ t('referrals.admin.reviewIntro') }}</p>
            <p v-if="!data.review.length" class="referrals-admin__empty">{{ t('referrals.admin.reviewEmpty') }}</p>
            <div v-for="item in data.review" :key="item.id" class="referrals-admin__review">
              <span class="referrals-admin__who"><strong>{{ item.name || item.email }}</strong><small>{{ item.email }} · {{ t('referrals.admin.invitedBy', { name: item.referrer }) }} · {{ t(`referrals.admin.flags.${item.flag}`) }} · {{ formatDate(item.createdAt) }}</small></span>
              <span class="referrals-admin__actions">
                <button type="button" class="button button--outline button--small referrals-admin__reject" :disabled="busy" @click="decide(item, 'reject')">{{ t('referrals.admin.reject') }}</button>
                <button type="button" class="button button--outline button--small" :disabled="busy" @click="decide(item, 'accept')">{{ t('referrals.admin.accept') }}</button>
              </span>
            </div>
          </section>
        </template>
      </main>
    </div>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminReferralsView.scss"></style>
