<script setup>
import EmailPreviewFrame from '../components/shared/EmailPreviewFrame.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RefreshCw, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminReadOnlyTable from '../components/admin/AdminReadOnlyTable.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate } from '../lib/api'

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
const logCursor = ref('')
const actionBusy = ref(false)
const showFailed = ref(false)
const workers = ref([])
const workerMode = ref('cli')
const logDialog = ref(null)
const selectedLog = ref(null)
const emailPreview = ref(null)
const previewLoading = ref(false)
const previewError = ref('')
const previewTab = ref('html')
let previewRequestId = 0
const mailFile = computed(() => /\.mail-\d{4}-\d{2}-\d{2}\.log$/.test(file.value))
let requestId = 0

const columns = computed(() => ({
  tasks: ['name', 'schedule', 'lastStartedAt', 'lastFinishedAt', 'nextExpectedAt', 'status', 'actions'],
  queues: showFailed.value ? ['type', 'attempts', 'error_code', 'actions'] : ['id', 'kind', 'count', 'delayed', 'inFlight'],
  logs: mailFile.value ? ['time', 'recipient', 'subject', 'mailStatus', 'responseCode', 'message'] : ['time', 'channel', 'level', 'message'],
}[tab.value].map((key) => ({ key, label: t(`${['recipient', 'subject', 'mailStatus', 'responseCode'].includes(key) ? 'emailLogs' : 'adminTools'}.columns.${key}`) }))))
const labels = computed(() => ({
  caption: t(`adminTools.${tab.value}`),
  empty: t('adminTools.empty'),
  pageSize: t('adminDashboard.pageSize'),
  pagination: t('adminDashboard.pagination'),
  previous: t('adminDashboard.previous'),
  next: t('adminDashboard.next'),
}))

function date(value) {
  if (!value) return '—'
  const language = locale.value === 'sr' ? 'sr-Latn' : locale.value === 'cnr' ? 'sr-Latn-ME' : locale.value
  return `${formatDate(value)} ${new Intl.DateTimeFormat(language, { timeStyle: 'medium' }).format(new Date(value))}`
}

function taskStatus(row) {
  return row.status === 'scheduled' && row.overdue ? 'overdue' : row.status
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
      const query = new URLSearchParams({ page: String(page.value), pageSize: String(pageSize.value), file: file.value === '__all__' ? '' : file.value, cursor: logCursor.value })
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
      logCursor.value = result.meta.cursor
      page.value = result.meta.page ?? page.value
    }
    access.value = 'allowed'
  } catch (cause) {
    if (ticket !== requestId) return
    rows.value = []
    meta.value = {}
    files.value = []
    closeLog()
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

async function openLog(row) {
  const ticket = ++previewRequestId
  const userId = currentUser.value?.id
  selectedLog.value = row
  emailPreview.value = null
  previewError.value = ''
  previewTab.value = 'html'
  previewLoading.value = Boolean(row.mail?.emailId)
  logDialog.value?.showModal()
  if (!row.mail?.emailId) return
  try {
    const result = await apiGet(`/admin/tools/email-preview/${encodeURIComponent(row.mail.emailId)}`)
    if (ticket !== previewRequestId || currentUser.value?.id !== userId) return
    if (!result.data.html && !result.data.text) { previewError.value = t('emailLogs.previewUnavailable'); return }
    emailPreview.value = result.data
    if (!result.data.html) previewTab.value = 'text'
  } catch (cause) {
    if (ticket !== previewRequestId || currentUser.value?.id !== userId) return
    previewError.value = t('emailLogs.previewUnavailable')
    if ([401, 403].includes(cause.status)) closeLog()
  } finally { if (ticket === previewRequestId) previewLoading.value = false }
}

function closeLog() {
  previewRequestId++
  selectedLog.value = null
  emailPreview.value = null
  previewLoading.value = false
  previewError.value = ''
  logDialog.value?.close()
}

function refresh() {
  page.value = 1
  logCursor.value = ''
  rows.value = []
  meta.value = {}
  load()
}

function navigatePage(target) {
  if (loading.value || target < 1 || target === page.value) return
  const count = meta.value.total == null ? page.value + Number(Boolean(meta.value.hasMore)) : Math.max(1, Math.ceil(meta.value.total / pageSize.value))
  if (target > count) return
  page.value = target
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
watch(() => [currentUser.value?.id, Boolean(currentUser.value?.isAdmin)], () => {
  closeLog()
  access.value = 'loading'
  refresh()
})
onMounted(load)
onBeforeUnmount(() => { requestId++; previewRequestId++ })
</script>

<template>
  <AdminPage class="admin-dashboard">
    <div v-if="access === 'loading'" class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main"><LoadingSkeleton variant="dashboard" :label="t('adminTools.loading')" /></main>
    </div>
    <div v-else-if="access === 'anonymous'" class="admin-dashboard__state">
      <StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage>
      <LocalizedLink class="button button--dark" :to="{ name: 'login' }">{{ t('auth.signIn') }}</LocalizedLink>
    </div>
    <div v-else-if="access === 'forbidden'" class="admin-dashboard__state"><StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage></div>
    <div v-else-if="access === 'error'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ error }}</StatusMessage>
      <button type="button" class="admin-tools__refresh" :aria-label="t('adminTools.refresh')" :title="t('adminTools.refresh')" :disabled="loading" @click="refresh"><RefreshCw :size="18" aria-hidden="true" /></button>
    </div>
    <div v-else class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main admin-tools">
        <header class="admin-dashboard__heading admin-tools__heading">
          <div><h1>{{ t('adminTools.title') }}</h1><p>{{ t('adminTools.description') }}</p></div>
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
          <AdminReadOnlyTable :columns="columns" :rows="rows" :labels="labels" :page="page" :page-size="pageSize" :total="meta.total ?? null" :has-more="Boolean(meta.hasMore)" :busy="loading" :show-empty="!error" @page-size="pageSize = $event" @page="navigatePage">
            <template #toolbar>
              <div class="admin-tools__toolbar">
                <button type="button" class="admin-tools__refresh" :aria-label="t('adminTools.refresh')" :title="t('adminTools.refresh')" :disabled="loading || actionBusy" @click="refresh"><RefreshCw :size="18" aria-hidden="true" /></button>
                <button v-if="tab === 'tasks'" type="button" class="button button--dark" :disabled="actionBusy || loading" @click="action('tasks/register')">{{ t('adminTools.register') }}</button>
                <button v-if="tab === 'queues'" type="button" class="button button--outline" @click="showFailed = !showFailed">{{ t(showFailed ? 'adminTools.queues' : 'adminTools.failedJobs') }}</button>
                <label v-if="tab === 'logs'" class="admin-tools__file">{{ t('adminTools.selectLog') }}
                  <select v-model="file" :aria-label="t('adminTools.selectLog')" :disabled="loading"><option value="" disabled>{{ t('adminTools.chooseLog') }}</option><option value="__all__">{{ t('adminTools.allLogs') }}</option><option v-for="entry in files" :key="entry.name" :value="entry.name">{{ entry.name }}</option></select>
                </label>
              </div>
            </template>
            <template #cell-name="{ value }"><code>{{ value }}</code></template>
            <template #cell-actions="{ row }">
              <AdminRowActions :label="t('adminDashboard.rowActions', { name: row.name ?? row.type })">
                <template v-if="tab === 'tasks'">
                  <button v-for="name in ['run', 'scheduled', 'immediate', 'inactive']" :key="name" class="admin-row-actions__item" role="menuitem" type="button" :disabled="actionBusy || row.status === 'unregistered' || (name === 'run' && Boolean(row.activeJobId))" @click="action(`tasks/${encodeURIComponent(row.name)}/${name}`)">{{ t(`adminTools.action.${name}`) }}</button>
                </template>
                <template v-else>
                  <button class="admin-row-actions__item" role="menuitem" type="button" :disabled="actionBusy" @click="action(`failed/${row.id}/retry`)">{{ t('adminTools.retry') }}</button>
                  <button class="admin-row-actions__item admin-row-actions__item--danger" role="menuitem" type="button" :disabled="actionBusy" @click="action(`failed/${row.id}/discard`)">{{ t('adminTools.discard') }}</button>
                </template>
              </AdminRowActions>
            </template>
            <template #cell-kind="{ value }">{{ t(`adminTools.${value}Kind`) }}</template>
            <template #cell-lastStartedAt="{ value }">{{ date(value) }}</template>
            <template #cell-lastFinishedAt="{ value, row }">{{ date(value) }}<small v-if="row.lastOutcome" class="admin-tools__command"><span class="admin-tools__status" :class="`admin-tools__status--${row.lastOutcome}`">{{ t(`adminTools.statuses.${row.lastOutcome}`) }}</span></small></template>
            <template #cell-nextExpectedAt="{ value }">{{ date(value) }}</template>
            <template #cell-status="{ row }"><span class="admin-tools__status" :class="`admin-tools__status--${taskStatus(row)}`">{{ t(`adminTools.statuses.${taskStatus(row)}`) }}</span></template>
            <template #cell-time="{ value }">{{ date(value) }}</template>
            <template #cell-recipient="{ row }"><span class="admin-tools__mail-address">{{ row.mail?.recipients?.join(', ') || '—' }}</span></template>
            <template #cell-subject="{ row }"><span class="admin-tools__mail-subject">{{ row.mail?.subject || '—' }}</span></template>
            <template #cell-mailStatus="{ row }"><span v-if="row.mail" class="admin-tools__status" :class="`admin-tools__status--${row.mail.status === 'accepted' ? 'success' : ['failed', 'queue_failed'].includes(row.mail.status) ? 'failed' : 'queued'}`">{{ t(`emailLogs.status.${row.mail.status}`) }}</span></template>
            <template #cell-responseCode="{ row }">{{ row.mail?.protocol }} {{ row.mail?.responseCode ?? '—' }}</template>
            <template #cell-file="{ value }"><code>{{ value }}</code></template>
            <template #cell-message="{ row, value }"><button type="button" class="admin-tools__log-preview" @click="openLog(row)">{{ row.mail ? t('emailLogs.open') : value }}</button><small v-if="row.truncated">{{ t('adminTools.truncated') }}</small></template>
          </AdminReadOnlyTable>
          <dialog ref="logDialog" class="admin-tools__dialog wave-modal wave-modal--large wave-modal--padded" aria-labelledby="log-detail-title" @click="($event.target === logDialog) && closeLog()" @cancel="closeLog" aria-modal="true">
            <header class="wave-modal__header">
              <h2 id="log-detail-title" class="wave-modal__title">{{ t('adminTools.logDetail') }}</h2>
              <button class="wave-modal__close" type="button" :aria-label="t('adminTools.close')" @click="closeLog"><X :size="20" aria-hidden="true" /></button>
            </header>
            <p>{{ date(selectedLog?.time) }} · {{ selectedLog?.channel }} · {{ selectedLog?.level }}</p>
            <template v-if="selectedLog?.mail">
              <dl class="admin-tools__mail-meta">
                <dt>{{ t('emailLogs.columns.subject') }}</dt><dd>{{ selectedLog.mail.subject }}</dd>
                <dt>{{ t('emailLogs.sender') }}</dt><dd>{{ selectedLog.mail.sender }}</dd>
                <dt>{{ t('emailLogs.columns.recipient') }}</dt><dd>{{ selectedLog.mail.recipients?.join(', ') }}</dd>
                <dt>{{ t('emailLogs.columns.mailStatus') }}</dt><dd>{{ t(`emailLogs.status.${selectedLog.mail.status}`) }}</dd>
                <dt>{{ t('emailLogs.columns.responseCode') }}</dt><dd>{{ selectedLog.mail.protocol }} {{ selectedLog.mail.responseCode ?? '—' }}</dd>
                <dt>{{ t('emailLogs.emailId') }}</dt><dd>{{ selectedLog.mail.emailId || '—' }}</dd>
              </dl>
              <p v-if="selectedLog.mail.status === 'accepted'" class="admin-tools__hint">{{ t('emailLogs.acceptedHint') }}</p>
              <pre v-if="selectedLog.mail.response" class="admin-tools__log">{{ selectedLog.mail.response }}</pre>
              <StatusMessage v-if="selectedLog.mail.error" variant="error"><span class="admin-tools__mail-error">{{ selectedLog.mail.error }}</span></StatusMessage>
              <p v-if="selectedLog.mail.errorTruncated || selectedLog.mail.responseTruncated">{{ t('adminTools.truncated') }}</p>
              <p v-if="selectedLog.mail.reason">{{ t(`emailLogs.reason.${selectedLog.mail.reason}`) }}</p>
              <p v-if="previewLoading" role="status">{{ t('adminTools.loading') }}</p>
              <StatusMessage v-if="previewError" variant="error">{{ previewError }}</StatusMessage>
              <template v-if="emailPreview">
                <p class="admin-tools__hint">{{ t('emailLogs.previewHint') }}</p>
                <div class="button-row">
                  <button v-if="emailPreview.html" type="button" class="button button--outline" :aria-pressed="previewTab === 'html'" @click="previewTab = 'html'">HTML</button>
                  <button type="button" class="button button--outline" :aria-pressed="previewTab === 'text'" @click="previewTab = 'text'">{{ t('emailLogs.text') }}</button>
                </div>
                <EmailPreviewFrame v-if="previewTab === 'html'" class="admin-tools__email-frame" :html="emailPreview.html" :title="t('emailLogs.preview')" />
                <pre v-else class="admin-tools__log">{{ emailPreview.text || '—' }}</pre>
                <p v-if="emailPreview.attachments?.length">{{ t('emailLogs.attachments') }}: {{ emailPreview.attachments.join(', ') }}</p>
              </template>
              <p v-else-if="!previewLoading && !previewError">{{ t('emailLogs.previewUnavailable') }}</p>
            </template>
            <pre v-else class="admin-tools__log">{{ selectedLog?.detail ?? selectedLog?.message }}</pre>
            <button type="button" class="button button--outline" @click="closeLog">{{ t('adminTools.close') }}</button>
          </dialog>
        </section>
      </main>
    </div>
  </AdminPage>
</template>

<style scoped lang="scss" src="../scss/views/AdminToolsView.scss"></style>
