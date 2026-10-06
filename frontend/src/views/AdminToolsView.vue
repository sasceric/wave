<script setup>
import AdminPage from '../components/admin/AdminPage.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RefreshCw } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminReadOnlyTable from '../components/admin/AdminReadOnlyTable.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'

const { locale, t } = useI18n()
const tabs = ['tasks', 'queues', 'logs']
const tab = ref('tasks')
const tabButtons = ref([])
const access = ref('loading')
const loading = ref(false)
const error = ref('')
const rows = ref([])
const meta = ref({})
const files = ref([])
const file = ref('')
const page = ref(1)
const pageSize = ref(25)
const cursors = ref([''])
const actionBusy = ref(false)
const showFailed = ref(false)
const workers = ref([])
const workerMode = ref('cli')
const logDialog = ref(null)
const selectedLog = ref(null)
let requestId = 0

const columns = computed(() => ({
  tasks: ['name', 'schedule', 'lastStartedAt', 'lastFinishedAt', 'nextExpectedAt', 'status', 'actions'],
  queues: showFailed.value ? ['type', 'attempts', 'error_code', 'actions'] : ['id', 'kind', 'count', 'delayed', 'inFlight'],
  logs: ['time', 'channel', 'level', 'message'],
}[tab.value].map((key) => ({ key, label: t(`adminTools.columns.${key}`) }))))
const labels = computed(() => ({
  caption: t(`adminTools.${tab.value}`),
  empty: t('adminTools.empty'),
  pageSize: t('adminDashboard.pageSize'),
  pagination: t('adminDashboard.pagination'),
  previous: t('adminDashboard.previous'),
  next: t('adminDashboard.next'),
  page: t('adminTools.page', { page: page.value }),
}))

function date(value) {
  if (!value) return '—'
  const language = locale.value === 'sr' ? 'sr-Latn' : locale.value === 'cnr' ? 'sr-Latn-ME' : locale.value
  return new Intl.DateTimeFormat(language, { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value))
}

async function load() {
  const ticket = ++requestId
  const selected = tab.value
  loading.value = true
  error.value = ''
  try {
    let result
    if (selected === 'logs') {
      const listed = await apiGet('/admin/tools/log-files')
      if (ticket !== requestId) return
      files.value = listed.data.files
      if (file.value && file.value !== '__all__' && !files.value.some((entry) => entry.name === file.value)) {
        file.value = ''
        return
      }
      if (!file.value) {
        rows.value = []
        meta.value = {}
        access.value = 'allowed'
        return
      }
      const query = new URLSearchParams({ pageSize: String(pageSize.value), file: file.value === '__all__' ? '' : file.value, cursor: cursors.value[page.value - 1] ?? '' })
      result = await apiGet(`/admin/tools/logs?${query}`)
    } else {
      result = await apiGet(`/admin/tools/${selected === 'queues' && showFailed.value ? 'failed' : selected}?page=${page.value}&pageSize=${pageSize.value}`)
      workers.value = result.workers ?? []
      workerMode.value = result.workerConfig?.mode ?? 'cli'
    }
    if (ticket !== requestId) return
    rows.value = result.data
    meta.value = result.meta
    if (selected === 'logs') {
      cursors.value[page.value - 1] = result.meta.cursor
      cursors.value[page.value] = result.meta.nextCursor
    }
    access.value = 'allowed'
  } catch (cause) {
    if (ticket !== requestId) return
    rows.value = []
    meta.value = {}
    files.value = []
    if (cause.status === 401) access.value = 'anonymous'
    else if (cause.status === 403) access.value = 'forbidden'
    else {
      error.value = t('adminTools.loadError')
      if (access.value !== 'allowed') access.value = 'error'
    }
  } finally {
    if (ticket === requestId) loading.value = false
  }
}

async function action(path) {
  if (actionBusy.value) return
  actionBusy.value = true
  error.value = ''
  try {
    await apiRequest(`/admin/tools/${path}`, { method: 'POST' })
    refresh()
  } catch (cause) {
    error.value = cause.message
  } finally {
    actionBusy.value = false
  }
}

function openLog(row) {
  selectedLog.value = row
  logDialog.value?.showModal()
}

function refresh() {
  page.value = 1
  cursors.value = ['']
  rows.value = []
  meta.value = {}
  load()
}

function navigate(direction) {
  if (loading.value || (direction < 0 && page.value <= 1) || (direction > 0 && !meta.value.hasMore)) return
  page.value += direction
  load()
}

function tabKeydown(event, index) {
  let next
  if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
  else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = tabs.length - 1
  else return
  event.preventDefault()
  tab.value = tabs[next]
  tabButtons.value[next]?.focus()
}

watch([tab, locale, pageSize, file, showFailed], refresh)
watch(() => currentUser.value?.id, () => {
  access.value = 'loading'
  refresh()
})
onMounted(load)
onBeforeUnmount(() => { requestId++ })
</script>

<template>
  <AdminPage class="admin-dashboard">
    <div v-if="access === 'loading'" class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main"><LoadingSkeleton variant="dashboard" :label="t('adminTools.loading')" /></main>
    </div>
    <div v-else-if="access === 'anonymous'" class="admin-dashboard__state">
      <StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage>
      <LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">{{ t('auth.signIn') }}</LocalizedLink>
    </div>
    <div v-else-if="access === 'forbidden'" class="admin-dashboard__state"><StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage></div>
    <div v-else-if="access === 'error'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ error }}</StatusMessage>
      <button type="button" class="button button--outline" :disabled="loading" @click="refresh">{{ t('adminTools.refresh') }}</button>
    </div>
    <div v-else class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main admin-tools">
        <header class="admin-dashboard__heading admin-tools__heading">
          <div><h1>{{ t('adminTools.title') }}</h1><p>{{ t('adminTools.description') }}</p></div>
          <button type="button" class="button button--outline" :disabled="loading" @click="refresh"><RefreshCw :size="16" aria-hidden="true" />{{ t('adminTools.refresh') }}</button>
        </header>
        <div class="admin-tools__tabs" role="tablist" :aria-label="t('adminTools.title')">
          <button v-for="(name, index) in tabs" :id="`tools-tab-${name}`" :key="name" :ref="(element) => { tabButtons[index] = element }" type="button" role="tab" :aria-selected="tab === name" :aria-controls="`tools-panel-${name}`" :tabindex="tab === name ? 0 : -1" @click="tab = name" @keydown="tabKeydown($event, index)">{{ t(`adminTools.${name}`) }}</button>
        </div>
        <section :id="`tools-panel-${tab}`" role="tabpanel" :aria-labelledby="`tools-tab-${tab}`" :aria-busy="loading">
          <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
          <p v-if="tab === 'tasks'" class="admin-tools__hint">{{ t('adminTools.scheduleHint') }}</p>
          <p v-if="tab === 'queues'" class="admin-tools__hint">{{ t('adminTools.queueHint') }} {{ t(`adminTools.workerMode.${workerMode}`) }} {{ t('adminTools.workers', { count: workers.length }) }}</p>
          <p v-if="tab === 'logs'" class="admin-tools__hint">{{ t('adminTools.logsHint') }}</p>
          <StatusMessage v-if="meta.changed">{{ t('adminTools.logsChanged') }}</StatusMessage>
          <StatusMessage v-if="meta.limited">{{ t('adminTools.logsLimited') }}</StatusMessage>
          <p v-if="loading" class="sr-only" role="status">{{ t('adminTools.loading') }}</p>
          <AdminReadOnlyTable :columns="columns" :rows="rows" :labels="labels" :page="page" :page-size="pageSize" :has-more="Boolean(meta.hasMore)" :busy="loading" :show-empty="!error" @page-size="pageSize = $event" @previous="navigate(-1)" @next="navigate(1)">
            <template #toolbar>
              <button v-if="tab === 'tasks'" type="button" class="button button--dark" :disabled="actionBusy || loading" @click="action('tasks/register')">{{ t('adminTools.register') }}</button>
              <button v-if="tab === 'queues'" type="button" class="button button--outline" @click="showFailed = !showFailed">{{ t(showFailed ? 'adminTools.queues' : 'adminTools.failedJobs') }}</button>
              <label v-if="tab === 'logs'" class="admin-tools__file">{{ t('adminTools.selectLog') }}
                <select v-model="file" :aria-label="t('adminTools.selectLog')" :disabled="loading"><option value="" disabled>{{ t('adminTools.chooseLog') }}</option><option value="__all__">{{ t('adminTools.allLogs') }}</option><option v-for="entry in files" :key="entry.name" :value="entry.name">{{ entry.name }}</option></select>
              </label>
            </template>
            <template #cell-name="{ value }"><code>{{ value }}</code></template>
            <template #cell-actions="{ row }">
              <details v-if="tab === 'tasks'" class="admin-tools__actions">
                <summary>{{ t('adminTools.actions') }}</summary>
                <button v-for="name in ['run', 'scheduled', 'immediate', 'inactive']" :key="name" type="button" :disabled="actionBusy || row.status === 'unregistered' || (name === 'run' && Boolean(row.activeJobId))" @click="action(`tasks/${encodeURIComponent(row.name)}/${name}`)">{{ t(`adminTools.action.${name}`) }}</button>
              </details>
              <div v-else class="admin-tools__actions">
                <button type="button" :disabled="actionBusy" @click="action(`failed/${row.id}/retry`)">{{ t('adminTools.retry') }}</button>
                <button type="button" :disabled="actionBusy" @click="action(`failed/${row.id}/discard`)">{{ t('adminTools.discard') }}</button>
              </div>
            </template>
            <template #cell-kind="{ value }">{{ t(`adminTools.${value}Kind`) }}</template>
            <template #cell-lastStartedAt="{ value }">{{ date(value) }}</template>
            <template #cell-lastFinishedAt="{ value, row }">{{ date(value) }}<small v-if="row.lastOutcome" class="admin-tools__command">{{ t(`adminTools.statuses.${row.lastOutcome}`) }}</small></template>
            <template #cell-nextExpectedAt="{ value }">{{ date(value) }}</template>
            <template #cell-status="{ value }"><span class="admin-tools__status" :class="`admin-tools__status--${value}`">{{ t(`adminTools.statuses.${value}`) }}</span></template>
            <template #cell-time="{ value }">{{ date(value) }}</template>
            <template #cell-file="{ value }"><code>{{ value }}</code></template>
            <template #cell-message="{ row, value }"><button type="button" class="admin-tools__log-preview" @click="openLog(row)">{{ value }}</button><small v-if="row.truncated">{{ t('adminTools.truncated') }}</small></template>
          </AdminReadOnlyTable>
          <dialog ref="logDialog" class="admin-tools__dialog" aria-labelledby="log-detail-title" @click="($event.target === logDialog) && logDialog.close()">
            <h2 id="log-detail-title">{{ t('adminTools.logDetail') }}</h2>
            <p>{{ date(selectedLog?.time) }} · {{ selectedLog?.channel }} · {{ selectedLog?.level }}</p>
            <pre class="admin-tools__log">{{ selectedLog?.detail ?? selectedLog?.message }}</pre>
            <button type="button" class="button button--outline" @click="logDialog.close()">{{ t('adminTools.close') }}</button>
          </dialog>
        </section>
      </main>
    </div>
  </AdminPage>
</template>

<style scoped lang="scss" src="../scss/views/AdminToolsView.scss"></style>
