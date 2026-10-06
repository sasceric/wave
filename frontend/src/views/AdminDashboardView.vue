<script setup>
import AdminMetrics from '../components/admin/AdminMetrics.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import {
  ArrowUpRight,
  Check,
  Building2,
  ClipboardCheck,
  Eye,
  Megaphone,
  Trash2,
  Users,
} from '@lucide/vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AdminMultiSelect from '../components/admin/AdminMultiSelect.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import AdminEmailTemplates from '../components/admin/AdminEmailTemplates.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'

const route = useRoute()
const { locale, t } = useI18n()
const dashboard = ref(null)
const accessState = ref('loading')
const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
const approving = ref(false)
const error = ref('')
const notice = ref('')
const deleteRequest = ref(null)
const bulkApprovalRequest = ref(null)
const query = ref('')
const statusFilter = ref('all')
const creatorMode = ref('latest')
const featuredCreatorIds = ref([])
const featuredCompanyIds = ref([])
const featuredCampaignIds = ref([])

const listRows = ref([])
const listTotal = ref(0)
const listLoading = ref(false)
const listPage = ref(1)
let listVersion = 0
let dashboardVersion = 0
let searchTimer
let disposed = false
const listOptions = ref({ page: 1, limit: 25, sort: '', direction: 'asc' })
const candidates = reactive(Object.fromEntries(
  ['creators', 'companies', 'campaigns'].map((kind) => [kind, {
    rows: [], total: 0, page: 0, loading: false, error: false, q: '', version: 0, timer: null,
  }]),
))
const isList = computed(() => ['creators', 'companies', 'campaigns', 'registrations'].includes(section.value))

const section = computed(() => route.meta.adminSection ?? 'overview')
const pageTitle = computed(() => t(
  section.value === 'email-templates'
    ? 'adminDashboard.emailTemplatesPageTitle'
    : `adminDashboard.${section.value}PageTitle`,
))

const creatorOptions = computed(() => (candidates.creators.rows).map((creator) => ({
  id: creator.id,
  label: creator.displayName,
  description: [creator.category, creator.location].filter(Boolean).join(' · '),
})))

const campaignOptions = computed(() => (candidates.campaigns.rows).map((campaign) => ({
  id: campaign.id,
  label: campaign.title,
  description: campaign.company.name,
})))

const companyOptions = computed(() => (candidates.companies.rows).map((company) => ({
  id: company.id,
  label: company.name,
  description: company.industry,
})))

const selectionOptions = computed(() => ({
  creators: (dashboard.value?.creators ?? []).map((row) => ({ id: row.id, label: row.displayName })),
  companies: (dashboard.value?.companies ?? []).map((row) => ({ id: row.id, label: row.name })),
  campaigns: (dashboard.value?.campaigns ?? []).map((row) => ({ id: row.id, label: row.title })),
}))

const multiSelectLabels = computed(() => ({
  loading: t('adminDashboard.loading'),
  loadMore: t('directoryLoading.loadMore'),
  search: t('adminDashboard.searchOptions'),
  selectVisible: t('adminDashboard.selectVisible'),
  deselectVisible: t('adminDashboard.deselectVisible'),
  clear: t('adminDashboard.clearSelection'),
  noSelection: t('adminDashboard.noSelection'),
  noResults: t('adminDashboard.noOptions'),
  selectedCount: (count) => t('adminDashboard.selectedOptions', { count }),
  remove: (name) => t('adminDashboard.removeOption', { name }),
}))

const metrics = computed(() => {
  if (!dashboard.value) return []

  return [
    {
      key: 'users',
      label: t('adminDashboard.users'),
      detail: t('adminDashboard.metricAllAccounts'),
      icon: Users,
      tone: 'sage',
      group: 'stats',
    },
    {
      key: 'creators',
      label: t('adminDashboard.creators'),
      detail: t('adminDashboard.metricCreatorProfiles'),
      icon: Users,
      tone: 'blue',
      group: 'stats',
      routeName: 'admin-creators',
    },
    {
      key: 'companies',
      label: t('adminDashboard.companies'),
      detail: t('adminDashboard.metricCompanyProfiles'),
      icon: Building2,
      tone: 'coral',
      group: 'stats',
      routeName: 'admin-companies',
    },
    {
      key: 'campaigns',
      label: t('adminDashboard.campaigns'),
      detail: t('adminDashboard.metricCampaignListings'),
      icon: Megaphone,
      tone: 'violet',
      group: 'stats',
      routeName: 'admin-campaigns',
    },
    {
      key: 'pendingRegistrations',
      label: t('adminDashboard.pendingRegistrations'),
      detail: t('adminDashboard.metricRegistrationReview'),
      icon: ClipboardCheck,
      tone: 'amber',
      group: 'attention',
      routeName: 'admin-registrations',
    },
    {
      key: 'pendingApplications',
      label: t('adminDashboard.pendingApplications'),
      detail: t('adminDashboard.metricApplicationReview'),
      icon: ClipboardCheck,
      tone: 'blue',
      group: 'attention',
    },
  ].map((metric) => ({
    ...metric,
    value: dashboard.value.metrics[metric.key],
  }))
})

const bulkApprovalConfirmationMessage = computed(() => {
  if (!bulkApprovalRequest.value) return ''

  return t('adminDashboard.confirmBulkApprove', {
    count: bulkApprovalRequest.value.ids.length,
    skipped: bulkApprovalRequest.value.skipped,
  })
})

const overviewStats = computed(() => metrics.value.filter(({ group }) => group === 'stats'))
const pendingMetrics = computed(() => metrics.value.filter(({ group }) => group === 'attention'))
const pendingTotal = computed(() => pendingMetrics.value.reduce((total, metric) => total + metric.value, 0))

const currentRows = computed(() => listRows.value)

const tableColumns = computed(() => {
  const columns = {
    registrations: [
      { key: 'approved', label: t('adminDashboard.approvalStatus'), sortable: true, defaultSort: true },
      { key: 'name', label: t('adminDashboard.name'), sortable: true },
      { key: 'email', label: t('adminDashboard.email'), sortable: true },
      { key: 'accountType', label: t('adminDashboard.accountType'), sortable: true },
      { key: 'emailVerified', label: t('adminDashboard.emailVerification'), sortable: true },
    ],
    creators: [
      { key: 'displayName', label: t('adminDashboard.creator'), sortable: true },
      { key: 'category', label: t('adminDashboard.category'), sortable: true },
      { key: 'location', label: t('adminDashboard.location'), sortable: true },
    ],
    companies: [
      { key: 'name', label: t('adminDashboard.company'), sortable: true },
      { key: 'industry', label: t('adminDashboard.industry'), sortable: true },
      { key: 'verified', label: t('adminDashboard.verification'), sortable: true },
    ],
    campaigns: [
      { key: 'title', label: t('adminDashboard.campaign'), sortable: true },
      { key: 'company.name', label: t('adminDashboard.company'), sortable: true },
      { key: 'status', label: t('adminDashboard.status'), sortable: true },
    ],
  }
  return columns[section.value] ?? []
})

const tableLabels = computed(() => ({
  loading: t('adminDashboard.loading'),
  actions: t('adminDashboard.actions'),
  deleteSelected: t('adminDashboard.deleteSelected'),
  empty: t('adminDashboard.noMatches'),
  goToPage: (page) => t('adminDashboard.goToPage', { page }),
  next: t('adminDashboard.next'),
  pagination: t('adminDashboard.pagination'),
  pageSize: t('adminDashboard.pageSize'),
  previous: t('adminDashboard.previous'),
  selectPage: t('adminDashboard.selectPage'),
  selectRow: (name) => t('adminDashboard.selectRow', { name }),
  selected: (count) => t('adminDashboard.selected', { count }),
}))

const deleteConfirmationMessage = computed(() => {
  if (!deleteRequest.value) return ''

  return deleteRequest.value.name
    ? t('adminDashboard.confirmDeleteOne', { name: deleteRequest.value.name })
    : t('adminDashboard.confirmDelete', { count: deleteRequest.value.ids.length })
})

async function loadDashboard() {
  const version = ++dashboardVersion
  loading.value = true
  error.value = ''
  notice.value = ''

  try {
    const { data } = await apiGet(`/admin/dashboard?section=${section.value}`)
    if (disposed || version !== dashboardVersion) return
    dashboard.value = data
    creatorMode.value = data.creatorMode
    featuredCreatorIds.value = data.creators.filter(({ featured }) => featured).map(({ id }) => id)
    featuredCompanyIds.value = data.companies.filter(({ featured }) => featured).map(({ id }) => id)
    featuredCampaignIds.value = data.campaigns.filter(({ featured }) => featured).map(({ id }) => id)
    accessState.value = 'allowed'
    if (isList.value) await loadList()
    if (section.value === 'homepage') await Promise.all(Object.keys(candidates).map((kind) => loadCandidates(kind, true)))
  } catch (cause) {
    if (disposed || version !== dashboardVersion) return
    if (cause.status === 401) accessState.value = 'anonymous'
    else if (cause.status === 403) accessState.value = 'forbidden'
    else {
      error.value = cause.message || t('adminDashboard.loadError')
      accessState.value = 'error'
    }
  } finally {
    if (!disposed && version === dashboardVersion) loading.value = false
  }
}

async function loadList(options = listOptions.value) {
  if (!isList.value || disposed) return
  const version = ++listVersion
  listOptions.value = { ...options }
  listLoading.value = true
  error.value = ''
  const params = new URLSearchParams({ ...options, q: query.value.trim(), verified: statusFilter.value })
  if (!options.sort) params.delete('sort')
  try {
    const response = await apiGet(`/admin/catalog/${section.value}?${params}`)
    if (disposed || version !== listVersion) return
    listRows.value = response.data
    listTotal.value = response.meta.total
    listPage.value = response.meta.page
  } catch (cause) {
    if (!disposed && version === listVersion) error.value = cause.message || t('adminDashboard.loadError')
  } finally {
    if (!disposed && version === listVersion) listLoading.value = false
  }
}

async function loadCandidates(kind, reset = false) {
  const state = candidates[kind]
  if (disposed || (!reset && state.loading)) return
  if (reset) {
    state.page = 0
    state.rows = []
  }
  const version = ++state.version
  const viewVersion = dashboardVersion
  const page = state.page + 1
  state.loading = true
  state.error = false
  try {
    const params = new URLSearchParams({ page, limit: 25, q: state.q.trim() })
    const response = await apiGet(`/admin/catalog/${kind}?${params}`)
    if (disposed || version !== state.version || viewVersion !== dashboardVersion) return
    state.rows = reset ? response.data : [...state.rows, ...response.data]
    state.total = response.meta.total
    state.page = response.meta.page
    // Keep selected labels when searches replace the candidate page.
    const cache = new Map(dashboard.value[kind].map((row) => [row.id, row]))
    state.rows.forEach((row) => cache.set(row.id, row))
    dashboard.value[kind] = [...cache.values()]
  } catch (cause) {
    if (!disposed && version === state.version && viewVersion === dashboardVersion) {
      state.error = true
      error.value = cause.message || t('adminDashboard.loadError')
    }
  } finally {
    if (!disposed && version === state.version) state.loading = false
  }
}

function searchCandidates(kind, value) {
  const state = candidates[kind]
  state.q = value
  state.version++
  clearTimeout(state.timer)
  state.timer = setTimeout(() => loadCandidates(kind, true), 250)
}

async function saveCuration() {
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest('/admin/homepage', {
      method: 'PUT',
      body: {
        creatorMode: creatorMode.value,
        featuredCreatorIds: featuredCreatorIds.value,
        featuredCompanyIds: featuredCompanyIds.value,
        featuredCampaignIds: featuredCampaignIds.value,
      },
    })
    notice.value = t('adminDashboard.saved')
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.saveError')
  } finally {
    saving.value = false
  }
}

function requestDelete(ids, name = null) {
  if (ids.length === 0) return
  deleteRequest.value = { ids, name }
}

function requestRowDelete(row) {
  requestDelete([row.id], row.displayName ?? row.name ?? row.title ?? row.email ?? String(row.id))
}

function closeDeleteModal(open) {
  if (!open && !deleting.value) deleteRequest.value = null
}

async function confirmDelete() {
  const request = deleteRequest.value
  if (!request || deleting.value) return

  deleting.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/admin/${section.value}/bulk-delete`, { method: 'DELETE', body: { ids: request.ids } })
    await loadDashboard()
    notice.value = t('adminDashboard.deleted', { count: request.ids.length })
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.deleteError')
  } finally {
    deleting.value = false
    deleteRequest.value = null
  }
}

async function approveRegistration(registration) {
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/admin/registrations/${registration.id}/approve`, { method: 'POST' })
    await loadDashboard()
    notice.value = t('adminDashboard.registrationApproved')
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.saveError')
  }
}

function requestBulkApproval(selectedIds) {
  const selected = listRows.value.filter(({ id }) => selectedIds.includes(id))
  const eligible = selected.filter(({ approved, emailVerified, profileComplete }) => (
    !approved && emailVerified && profileComplete
  ))
  if (eligible.length === 0) {
    notice.value = t('adminDashboard.bulkApproveNoEligible')
    return
  }

  bulkApprovalRequest.value = {
    ids: eligible.map(({ id }) => id),
    skipped: selected.length - eligible.length,
  }
}

function closeBulkApprovalModal(open) {
  if (!open && !approving.value) bulkApprovalRequest.value = null
}

async function confirmBulkApproval() {
  const request = bulkApprovalRequest.value
  if (!request || approving.value) return

  approving.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest('/admin/registrations/bulk-approve', {
      method: 'POST',
      body: { ids: request.ids },
    })
    await loadDashboard()
    const skipped = request.skipped + data.skippedUnverified + data.skippedIncomplete
    notice.value = skipped > 0
      ? t('adminDashboard.bulkApprovedWithSkipped', { count: data.approved, skipped })
      : t('adminDashboard.bulkApproved', { count: data.approved })
  } catch (cause) {
    await loadDashboard()
    error.value = cause.message || t('adminDashboard.bulkApproveError')
  } finally {
    approving.value = false
    bulkApprovalRequest.value = null
  }
}

watch([locale, section], () => {
  query.value = ''
  statusFilter.value = 'all'
  listVersion++
  listRows.value = []
  listTotal.value = 0
  listPage.value = 1
  listOptions.value = { page: 1, limit: 25, sort: '', direction: 'asc' }
  loadDashboard()
})
watch([query, statusFilter], () => {
  listVersion++
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => loadList({ ...listOptions.value, page: 1 }), 250)
})
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(searchTimer)
  Object.values(candidates).forEach((state) => clearTimeout(state.timer))
})
onMounted(loadDashboard)
</script>

<template>
  <AdminPage class="admin-dashboard">
    <div v-if="(loading && !dashboard) || accessState === 'loading'" class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main"><LoadingSkeleton variant="dashboard" :label="t('adminDashboard.loading')" /></main>
    </div>
    <div v-else-if="accessState === 'anonymous'" class="admin-dashboard__state">
      <StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage>
      <LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">{{ t('auth.signIn') }}</LocalizedLink>
    </div>
    <div v-else-if="accessState === 'forbidden'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage>
    </div>
    <div v-else-if="accessState === 'error'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ error }}</StatusMessage>
    </div>
    <div v-else class="admin-dashboard__layout" :aria-busy="loading">
      <AccountSidebar
        :user="currentUser"
        :pending-registrations="dashboard.metrics.pendingRegistrations"
        :admin-layout="true"
      />

      <main class="admin-dashboard__main">
        <header class="admin-dashboard__heading">
          <h1>{{ pageTitle }}</h1>
        </header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <StatusMessage v-if="notice">{{ notice }}</StatusMessage>

        <section v-if="section === 'overview'" class="admin-overview">
          <section class="admin-overview__section">
            <header class="admin-overview__section-heading">
              <div>
                <h2>{{ t('adminDashboard.overviewSnapshot') }}</h2>
                <p>{{ t('adminDashboard.overviewSnapshotHint') }}</p>
              </div>
              <span>{{ t('adminDashboard.overviewEyebrow') }}</span>
            </header>
            <AdminMetrics class="admin-dashboard__metrics">
              <component
                :is="metric.routeName ? LocalizedLink : 'article'"
                v-for="metric in overviewStats"
                :key="metric.key"
                class="admin-dashboard__metric"
                :class="`admin-dashboard__metric--${metric.tone}`"
                :to="metric.routeName ? { name: metric.routeName } : undefined"
              >
                <span class="admin-dashboard__metric-icon">
                  <component :is="metric.icon" :size="19" aria-hidden="true" />
                </span>
                <span class="admin-dashboard__metric-copy">
                  <span>{{ metric.label }}</span>
                  <strong>{{ metric.value }}</strong>
                  <small>{{ metric.detail }}</small>
                </span>
                <ArrowUpRight v-if="metric.routeName" class="admin-dashboard__metric-arrow" :size="16" aria-hidden="true" />
              </component>
            </AdminMetrics>
          </section>

          <section class="admin-overview__section">
            <header class="admin-overview__section-heading">
              <div>
                <h2>{{ t('adminDashboard.needsAttention') }}</h2>
                <p>{{ t('adminDashboard.needsAttentionHint') }}</p>
              </div>
              <span class="admin-overview__attention-total">
                {{ pendingTotal }}
                {{ t('adminDashboard.pendingItems') }}
              </span>
            </header>
            <div class="admin-dashboard__attention">
              <component
                :is="metric.routeName ? LocalizedLink : 'article'"
                v-for="metric in pendingMetrics"
                :key="metric.key"
                class="admin-dashboard__attention-card"
                :class="`admin-dashboard__attention-card--${metric.tone}`"
                :to="metric.routeName ? { name: metric.routeName } : undefined"
              >
                <span class="admin-dashboard__attention-icon">
                  <component :is="metric.icon" :size="18" aria-hidden="true" />
                </span>
                <span class="admin-dashboard__attention-copy">
                  <strong>{{ metric.label }}</strong>
                  <small>{{ metric.detail }}</small>
                </span>
                <span class="admin-dashboard__attention-value">{{ metric.value }}</span>
                <ArrowUpRight v-if="metric.routeName" class="admin-dashboard__metric-arrow" :size="16" aria-hidden="true" />
              </component>
            </div>
          </section>
        </section>

        <section v-else-if="section === 'homepage'" class="admin-dashboard__panel">
          <form class="admin-dashboard__form" @submit.prevent="saveCuration">
            <div class="admin-settings-grid">
              <article class="admin-settings-card">
                <header class="admin-settings-card__heading">
                  <span class="admin-settings-card__icon"><Users :size="18" aria-hidden="true" /></span>
                  <div>
                    <h2>{{ t('adminDashboard.creatorPicks') }}</h2>
                    <p>{{ t('adminDashboard.creatorPicksHint') }}</p>
                  </div>
                </header>
                <label class="admin-dashboard__mode">
                  <span>{{ t('adminDashboard.creatorMode') }}</span>
                  <select v-model="creatorMode">
                    <option value="latest">{{ t('adminDashboard.latestCreators') }}</option>
                    <option value="featured">{{ t('adminDashboard.featuredCreators') }}</option>
                  </select>
                </label>
                <AdminMultiSelect
                  v-model="featuredCreatorIds"
                  :label="t('adminDashboard.selectFeaturedCreators')"
                  :options="creatorOptions"
                  :selection-options="selectionOptions.creators"
                  :remote="true"
                  :loading="candidates.creators.loading"
                  :has-more="candidates.creators.error || candidates.creators.rows.length < candidates.creators.total"
                  @search="searchCandidates('creators', $event)"
                  @load-more="loadCandidates('creators')"
                  :labels="multiSelectLabels"
                />
              </article>

              <article class="admin-settings-card">
                <header class="admin-settings-card__heading">
                  <span class="admin-settings-card__icon"><Megaphone :size="18" aria-hidden="true" /></span>
                  <div>
                    <h2>{{ t('adminDashboard.campaignPicks') }}</h2>
                    <p>{{ t('adminDashboard.campaignPicksHint') }}</p>
                  </div>
                </header>
                <AdminMultiSelect
                  v-model="featuredCampaignIds"
                  :label="t('adminDashboard.selectFeaturedCampaigns')"
                  :options="campaignOptions"
                  :selection-options="selectionOptions.campaigns"
                  :remote="true"
                  :loading="candidates.campaigns.loading"
                  :has-more="candidates.campaigns.error || candidates.campaigns.rows.length < candidates.campaigns.total"
                  @search="searchCandidates('campaigns', $event)"
                  @load-more="loadCandidates('campaigns')"
                  :labels="multiSelectLabels"
                />
              </article>

              <article class="admin-settings-card">
                <header class="admin-settings-card__heading">
                  <span class="admin-settings-card__icon"><Building2 :size="18" aria-hidden="true" /></span>
                  <div>
                    <h2>{{ t('adminDashboard.companyPicks') }}</h2>
                    <p>{{ t('adminDashboard.companyPicksHint') }}</p>
                  </div>
                </header>
                <AdminMultiSelect
                  v-model="featuredCompanyIds"
                  :label="t('adminDashboard.selectFeaturedCompanies')"
                  :options="companyOptions"
                  :selection-options="selectionOptions.companies"
                  :remote="true"
                  :loading="candidates.companies.loading"
                  :has-more="candidates.companies.error || candidates.companies.rows.length < candidates.companies.total"
                  @search="searchCandidates('companies', $event)"
                  @load-more="loadCandidates('companies')"
                  :labels="multiSelectLabels"
                />
              </article>
            </div>
            <footer class="admin-settings__footer">
              <span>{{ t('adminDashboard.homepageDescription') }}</span>
              <button class="button button--dark" type="submit" :disabled="saving">
                {{ saving ? t('adminDashboard.saving') : t('adminDashboard.save') }}
              </button>
            </footer>
          </form>
        </section>

        <AdminEmailTemplates v-else-if="section === 'email-templates'" />

        <section v-else class="admin-dashboard__list">
          <AdminDataTable
            :key="section"
            :columns="tableColumns"
            :total="listTotal"
            :current-page="listPage"
            :loading="listLoading"
            @change="loadList"
            :rows="currentRows"
            :labels="tableLabels"
            @delete-selected="requestDelete"
          >
            <template #bulk-actions="{ ids }">
              <button
                v-if="section === 'registrations' && ids.length"
                class="button button--outline"
                type="button"
                @click="requestBulkApproval(ids)"
              >
                <Check :size="15" aria-hidden="true" />
                {{ t('adminDashboard.bulkApproveSelected') }}
              </button>
            </template>
            <template #toolbar>
              <label class="admin-dashboard__search">
                <span class="sr-only">{{ t('adminDashboard.search') }}</span>
                <input v-model="query" type="search" :placeholder="t('adminDashboard.search')" />
              </label>
              <label v-if="section === 'companies'" class="admin-dashboard__filter">
                <span>{{ t('adminDashboard.filter') }}</span>
                <select v-model="statusFilter">
                  <option value="all">{{ t('adminDashboard.allStatuses') }}</option>
                  <option value="true">{{ t('adminDashboard.verified') }}</option>
                  <option value="false">{{ t('adminDashboard.unverified') }}</option>
                </select>
              </label>
            </template>
            <template #cell-emailVerified="{ value }">
              {{ value ? t('adminDashboard.verified') : t('adminDashboard.unverified') }}
            </template>
            <template #cell-approved="{ value }">{{ value ? t('adminDashboard.approved') : t('adminDashboard.pending') }}</template>
            <template #cell-accountType="{ value }">{{ t(`adminDashboard.${value}`) }}</template>
            <template #cell-status="{ value }">{{ t(`account.${value}`) }}</template>
            <template #cell-verified="{ value }">{{ value ? t('adminDashboard.verified') : t('adminDashboard.unverified') }}</template>
            <template #actions="{ row }">
              <AdminRowActions :label="t('adminDashboard.rowActions', { name: row.displayName ?? row.name ?? row.title ?? row.email })">
                <LocalizedLink
                  v-if="section === 'registrations' && row.profileSlug"
                  class="admin-row-actions__item"
                  role="menuitem"
                  :to="{
                    name: row.accountType === 'creator' ? 'creator-profile' : 'company-profile',
                    params: { slug: row.profileSlug },
                  }"
                >
                  <Eye :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.previewProfile') }}
                </LocalizedLink>
                <LocalizedLink
                  v-if="section === 'creators'"
                  class="admin-row-actions__item"
                  role="menuitem"
                  :to="{ name: 'creator-profile', params: { slug: row.slug } }"
                >
                  <Eye :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.view') }}
                </LocalizedLink>
                <LocalizedLink
                  v-else-if="section === 'companies'"
                  class="admin-row-actions__item"
                  role="menuitem"
                  :to="{ name: 'company-profile', params: { slug: row.slug } }"
                >
                  <Eye :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.view') }}
                </LocalizedLink>
                <LocalizedLink
                  v-else-if="section === 'campaigns'"
                  class="admin-row-actions__item"
                  role="menuitem"
                  :to="{ name: 'campaign-detail', params: { slug: row.slug } }"
                >
                  <Eye :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.view') }}
                </LocalizedLink>
                <button
                  v-else-if="section === 'registrations' && !row.approved && row.emailVerified && row.profileComplete"
                  class="admin-row-actions__item"
                  type="button"
                  role="menuitem"
                  @click="approveRegistration(row)"
                >
                  <Check :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.approve') }}
                </button>
                <span
                  v-else-if="section === 'registrations' && !row.approved && !row.profileComplete"
                  class="admin-row-actions__item"
                  aria-disabled="true"
                >
                  {{ t('account.profileIncomplete') }}
                </span>
                <button
                  class="admin-row-actions__item admin-row-actions__item--danger"
                  type="button"
                  role="menuitem"
                  @click="requestRowDelete(row)"
                >
                  <Trash2 :size="15" aria-hidden="true" />
                  {{ t('adminDashboard.delete') }}
                </button>
              </AdminRowActions>
            </template>
          </AdminDataTable>
        </section>
      </main>
    </div>
    <ConfirmationModal
      :open="deleteRequest !== null"
      :title="t('adminDashboard.confirmDeleteTitle')"
      :message="deleteConfirmationMessage"
      :confirm-label="t('adminDashboard.delete')"
      :cancel-label="t('adminDashboard.cancelAction')"
      :loading="deleting"
      @update:open="closeDeleteModal"
      @confirm="confirmDelete"
    />
    <ConfirmationModal
      :open="bulkApprovalRequest !== null"
      :title="t('adminDashboard.confirmBulkApproveTitle')"
      :message="bulkApprovalConfirmationMessage"
      :confirm-label="t('adminDashboard.bulkApproveSelected')"
      :cancel-label="t('adminDashboard.cancelAction')"
      :loading="approving"
      @update:open="closeBulkApprovalModal"
      @confirm="confirmBulkApproval"
    />
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminDashboardView.scss"></style>
