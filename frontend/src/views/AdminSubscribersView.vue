<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RefreshCw } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminReadOnlyTable from '../components/admin/AdminReadOnlyTable.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet } from '../lib/api'

const { locale, t } = useI18n()
const access = ref('loading')
const loading = ref(false)
const error = ref('')
const rows = ref([])
const meta = ref({ hasMore: false })
const page = ref(1)
const pageSize = ref(25)
let requestId = 0

const columns = computed(() => [
  { key: 'email', label: t('adminDashboard.email') },
  { key: 'locale', label: t('adminDashboard.subscriberLanguage') },
  { key: 'subscribedAt', label: t('adminDashboard.subscriberSubscribedAt') },
])

const labels = computed(() => ({
  caption: t('adminDashboard.subscribersCaption'),
  empty: t('adminDashboard.subscribersEmpty'),
  pageSize: t('adminDashboard.pageSize'),
  pagination: t('adminDashboard.pagination'),
  previous: t('adminDashboard.previous'),
  next: t('adminDashboard.next'),
  page: t('adminTools.page', { page: page.value }),
}))

function formatDate(value) {
  const language = locale.value === 'sr' ? 'sr-Latn' : locale.value === 'cnr' ? 'sr-Latn-ME' : locale.value
  return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

async function load() {
  const ticket = ++requestId
  loading.value = true
  error.value = ''

  try {
    const result = await apiGet(`/admin/subscribers?page=${page.value}&pageSize=${pageSize.value}`)
    if (ticket !== requestId) return
    rows.value = result.data
    meta.value = result.meta
    access.value = 'allowed'
  } catch (cause) {
    if (ticket !== requestId) return
    rows.value = []
    meta.value = { hasMore: false }
    if (cause.status === 401) {
      access.value = 'anonymous'
    } else if (cause.status === 403) {
      access.value = 'forbidden'
    } else {
      error.value = t('adminDashboard.subscribersLoadError')
      if (access.value !== 'allowed') access.value = 'error'
    }
  } finally {
    if (ticket === requestId) loading.value = false
  }
}

function refresh() {
  page.value = 1
  load()
}

function navigate(direction) {
  if (loading.value || (direction < 0 && page.value <= 1) || (direction > 0 && !meta.value.hasMore)) return
  page.value += direction
  load()
}

watch([locale, pageSize], () => {
  page.value = 1
  load()
})
watch(() => currentUser.value?.id, () => {
  access.value = 'loading'
  refresh()
})
onMounted(load)
onBeforeUnmount(() => { requestId++ })
</script>

<template>
  <AdminPage class="admin-subscribers">
    <div v-if="access === 'loading'" class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main">
        <LoadingSkeleton variant="dashboard" :label="t('adminDashboard.loading')" />
      </main>
    </div>
    <div v-else-if="access === 'anonymous'" class="admin-dashboard__state">
      <StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage>
      <LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">
        {{ t('auth.signIn') }}
      </LocalizedLink>
    </div>
    <div v-else-if="access === 'forbidden'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage>
    </div>
    <div v-else-if="access === 'error'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ error }}</StatusMessage>
      <button type="button" class="button button--outline" :disabled="loading" @click="refresh">
        {{ t('adminTools.refresh') }}
      </button>
    </div>
    <div v-else class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main">
        <header class="admin-subscribers__heading">
          <div>
            <p class="eyebrow">{{ t('adminDashboard.navMarketing') }}</p>
            <h1>{{ t('adminDashboard.subscribersPageTitle') }}</h1>
            <p>{{ t('adminDashboard.subscribersPageDescription') }}</p>
          </div>
          <button type="button" class="button button--outline" :disabled="loading" @click="refresh">
            <RefreshCw :size="16" aria-hidden="true" />
            {{ t('adminTools.refresh') }}
          </button>
        </header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <AdminReadOnlyTable
          :columns="columns"
          :rows="rows"
          :labels="labels"
          :page="page"
          :page-size="pageSize"
          :has-more="Boolean(meta.hasMore)"
          :busy="loading"
          :show-empty="!error"
          @page-size="pageSize = $event"
          @previous="navigate(-1)"
          @next="navigate(1)"
        >
          <template #cell-locale="{ value }">{{ value.toUpperCase() }}</template>
          <template #cell-subscribedAt="{ value }">{{ formatDate(value) }}</template>
        </AdminReadOnlyTable>
      </main>
    </div>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminSubscribersView.scss"></style>
