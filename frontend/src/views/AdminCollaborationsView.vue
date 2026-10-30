<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CheckCheck, ChevronDown, Eye, Handshake, Inbox, Info, X } from '@lucide/vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import FormModal from '../components/shared/FormModal.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import DatePicker from '../components/shared/DatePicker.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, formatDate, formatMoney } from '../lib/api'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const rows = ref([])
const summary = ref({ requests: 0, engaged: 0, completed: 0 })
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const error = ref('')
const selected = ref(null)
const q = ref('')
const kind = computed(() => route.query.kind === 'campaigns' ? 'campaigns' : 'packages')
const selectedStatus = computed(() => typeof route.query.status === 'string' ? route.query.status : 'requests')
const statuses = ['pending', 'discussing', 'offered', 'booked', 'completed', 'rejected', 'cancelled']
const statusOptions = computed(() => ['all', 'requests', 'engaged', ...statuses.filter(status => status !== 'booked')].map(value => ({ value, label: t(`adminCollaborations.statuses.${value}`) })))
const providerOptions = computed(() => ['', 'creator', 'agency'].map(value => ({ value, label: t(`adminCollaborations.${value || 'allProviders'}`) })))
const columns = computed(() => [
  { key: 'createdAt', label: t('adminCollaborations.createdAt'), sortable: true, defaultSort: true },
  { key: 'provider', label: t('adminCollaborations.provider'), sortable: true },
  { key: 'client', label: t('adminCollaborations.client'), sortable: true },
  { key: 'subject', label: t(`adminCollaborations.${kind.value === 'packages' ? 'packages' : 'campaign'}`), sortable: true },
  { key: 'status', label: t('adminCollaborations.status'), sortable: true },
])
const labels = computed(() => ({ caption: t('adminCollaborations.title'), loading: t('adminDashboard.loading'), actions: t('adminDashboard.actions'), empty: t('adminCollaborations.empty'), pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'), previous: t('adminDashboard.previous'), next: t('adminDashboard.next'), goToPage: value => t('adminDashboard.goToPage', { page: value }) }))
let revision = 0
let disposed = false
let timer
let controller
function filter(changes, resetPage = true) {
  const query = { ...route.query, ...changes }
  if (resetPage) delete query.page
  for (const key of Object.keys(query)) if (query[key] === '' || query[key] == null) delete query[key]
  void router.replace({ query })
}
function changeKind(value) {
  filter({ kind: value, status: 'requests', campaignId: undefined, origin: undefined, ...(route.query.campaignId ? { label: undefined } : {}) })
}
function statusStage(row) {
  if (row.status !== 'booked') return row.status
  return ['submitted', 'changes_requested'].includes(row.workStatus) ? row.workStatus : 'in_progress'
}
function statusLabel(row) {
  const stage = statusStage(row)
  return t(['in_progress', 'submitted', 'changes_requested'].includes(stage) ? `work.${stage}` : `adminCollaborations.statuses.${stage}`)
}
function changePage(options) { filter(options, false) }
async function load() {
  const current = ++revision
  controller?.abort()
  controller = new AbortController()
  selected.value = null
  rows.value = []
  total.value = 0
  summary.value = { requests: 0, engaged: 0, completed: 0 }
  error.value = ''
  const userId = currentUser.value?.id
  if (!currentUser.value?.isAdmin) { loading.value = false; return }
  loading.value = true
  const allowed = ['status', 'origin', 'q', 'providerType', 'providerId', 'clientId', 'campaignId', 'from', 'to', 'page', 'limit', 'sort', 'direction']
  const params = new URLSearchParams({ kind: kind.value, status: selectedStatus.value })
  for (const key of allowed) if (typeof route.query[key] === 'string') params.set(key, route.query[key])
  try {
    const result = await apiGet(`/admin/collaborations?${params}`, { signal: controller.signal })
    if (disposed || current !== revision || currentUser.value?.id !== userId) return
    rows.value = result.data
    summary.value = result.summary
    total.value = result.meta.total
    page.value = result.meta.page
  } catch (cause) { if (!disposed && current === revision && cause.name !== 'AbortError') error.value = cause.message }
  finally { if (!disposed && current === revision) loading.value = false }
}
watch(() => [route.query, locale.value, currentUser.value?.id, currentUser.value?.isAdmin], () => { q.value = typeof route.query.q === 'string' ? route.query.q : ''; void load() }, { immediate: true, deep: true })
watch(q, value => { clearTimeout(timer); if (value !== (route.query.q || '')) timer = setTimeout(() => filter({ q: value.trim() }), 300) })
onBeforeUnmount(() => { disposed = true; ++revision; clearTimeout(timer); controller?.abort() })
</script>
<template>
  <AdminPage class="admin-collaborations">
    <div v-if="currentUser?.isAdmin" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" admin-layout />
      <main class="admin-dashboard__main">
        <header class="admin-dashboard__header"><div><h1>{{ t('adminCollaborations.title') }}</h1><p>{{ t('adminCollaborations.intro') }}</p></div></header>
        <nav class="admin-collaborations__tabs" :aria-label="t('adminCollaborations.type')">
          <button v-for="value in ['packages', 'campaigns']" :key="value" type="button" :aria-pressed="kind === value" @click="changeKind(value)">{{ t(`adminCollaborations.${value}`) }}</button>
        </nav>
        <div v-if="route.query.providerId || route.query.clientId || route.query.campaignId" class="admin-collaborations__scope">
          <span>{{ t('adminCollaborations.filtered') }}: {{ route.query.label || route.query.providerId || route.query.clientId || route.query.campaignId }}</span>
          <button type="button" class="button button--outline" @click="filter({ providerId: undefined, clientId: undefined, campaignId: undefined, label: undefined, providerType: undefined })"><X :size="14" aria-hidden="true" />{{ t('adminCollaborations.clear') }}</button>
        </div>
        <div class="admin-collaborations__summary" role="group" :aria-label="t('adminCollaborations.status')" :aria-busy="loading">
          <button v-for="[key, status] in [['requests', 'requests'], ['engaged', 'engaged'], ['completed', 'completed']]" :key="key" type="button" :aria-pressed="selectedStatus === status" @click="filter({ status })">
            <component :is="key === 'requests' ? Inbox : key === 'engaged' ? Handshake : CheckCheck" :size="18" aria-hidden="true" />
            <span>{{ t(`adminCollaborations.${key === 'requests' && kind === 'campaigns' ? 'origin_all' : key}`) }}</span><strong>{{ summary[key] }}</strong>
          </button>
        </div>
        <div class="admin-collaborations__explanation">
          <p>{{ t('adminCollaborations.summaryHint') }}</p>
          <details>
            <summary><Info :size="14" aria-hidden="true" />{{ t('adminCollaborations.countHelp') }}<ChevronDown :size="13" aria-hidden="true" /></summary>
            <p>{{ t('adminCollaborations.countHint') }}</p>
          </details>
        </div>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <AdminDataTable :key="kind" :columns="columns" :rows="rows" :total="total" :current-page="page" :loading="loading" :labels="labels" :selectable="false" initial-sort-direction="desc" @change="changePage">
          <template #toolbar>
            <label class="admin-dashboard__search"><span class="sr-only">{{ t('adminDashboard.search') }}</span><input v-model="q" type="search" maxlength="200" :placeholder="t('adminCollaborations.search')" /></label>
            <SingleSelect :model-value="selectedStatus" :label="t('adminCollaborations.status')" :options="statusOptions" @update:model-value="filter({ status: $event })" />
            <SingleSelect v-if="kind === 'campaigns'" :model-value="route.query.origin || 'all'" :label="t('adminCollaborations.origin')" :options="['all', 'application', 'invitation'].map(value => ({ value, label: t(`adminCollaborations.origin_${value}`) }))" @update:model-value="filter({ origin: $event })" />
            <SingleSelect :model-value="route.query.providerType || ''" :label="t('adminCollaborations.provider')" :options="providerOptions" @update:model-value="filter({ providerType: $event, providerId: undefined, label: undefined })" />
            <DatePicker :model-value="route.query.from || ''" :label="t('adminCollaborations.from')" @update:model-value="filter({ from: $event })" />
            <DatePicker :model-value="route.query.to || ''" :label="t('adminCollaborations.to')" :min="route.query.from || ''" @update:model-value="filter({ to: $event })" />
          </template>
          <template #cell-createdAt="{ value }">{{ formatDate(value) }}</template>
          <template #cell-provider="{ value }"><strong>{{ value.name }}</strong><small>{{ t(`adminCollaborations.${value.type}`) }}</small></template>
          <template #cell-client="{ value }">{{ value.name }}</template>
          <template #cell-subject="{ row }"><span class="admin-collaborations__subject">{{ row.subject || t('adminCollaborations.customRequest') }}</span><small v-if="kind === 'campaigns'">{{ t(`adminCollaborations.origin_${row.origin}`) }}</small><small v-if="row.packages.length > 1">{{ t('adminCollaborations.packageCount', { count: row.packages.length }) }}</small></template>
          <template #cell-status="{ row }"><span class="admin-collaborations__status" :class="`is-${statusStage(row)}`">{{ statusLabel(row) }}</span></template>
          <template #actions="{ row }"><button type="button" class="button button--outline" :aria-label="`${t('adminCollaborations.details')}: ${row.provider.name} · ${row.client.name}`" @click="selected = row"><Eye :size="15" aria-hidden="true" />{{ t('adminCollaborations.details') }}</button></template>
        </AdminDataTable>
      </main>
    </div>
    <StatusMessage v-else variant="info">{{ t('adminCollaborations.adminOnly') }}</StatusMessage>
    <FormModal :open="Boolean(selected)" :title="t('adminCollaborations.details')" @update:open="!$event && (selected = null)">
      <template v-if="selected">
        <dl class="admin-collaborations__details">
          <div><dt>{{ t('adminCollaborations.provider') }}</dt><dd>{{ selected.provider.name }} · {{ t(`adminCollaborations.${selected.provider.type}`) }}</dd></div>
          <div><dt>{{ t('adminCollaborations.client') }}</dt><dd>{{ selected.client.name }}</dd></div>
          <div><dt>{{ t('adminCollaborations.status') }}</dt><dd><span class="admin-collaborations__status" :class="`is-${statusStage(selected)}`">{{ statusLabel(selected) }}</span></dd></div>
          <div><dt>{{ t('adminCollaborations.createdAt') }}</dt><dd>{{ formatDate(selected.createdAt) }}</dd></div>
          <div v-if="selected.acceptedAt"><dt>{{ t('adminCollaborations.acceptedAt') }}</dt><dd>{{ formatDate(selected.acceptedAt) }}</dd></div>
          <div v-if="selected.submittedAt"><dt>{{ t('adminCollaborations.submittedAt') }}</dt><dd>{{ formatDate(selected.submittedAt) }}</dd></div>
          <div v-if="selected.completedAt"><dt>{{ t('adminCollaborations.completedAt') }}</dt><dd>{{ formatDate(selected.completedAt) }}</dd></div>
          <div v-if="selected.amount !== null"><dt>{{ t('adminCollaborations.agreedAmount') }}</dt><dd>{{ formatMoney(selected.amount, selected.currency) }}</dd></div>
        </dl>
        <div><strong>{{ t(`adminCollaborations.${kind === 'packages' ? 'packages' : 'campaign'}`) }}</strong><ul v-if="selected.packages.length"><li v-for="(item, index) in selected.packages" :key="index">{{ item }}</li></ul><p v-else>{{ selected.subject || t('adminCollaborations.customRequest') }}</p></div>
        <p class="admin-collaborations__hint">{{ t('adminCollaborations.amountHint') }}</p>
      </template>
      <template #actions="{ close }"><button class="button button--outline" type="button" @click="close">{{ t('reviews.close') }}</button></template>
    </FormModal>
  </AdminPage>
</template>
<style lang="scss" src="../scss/views/AdminDashboardView.scss"></style>
<style lang="scss" src="../scss/views/AdminCollaborationsView.scss"></style>
