<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { BarChart3, CalendarDays, MapPin, Pencil, QrCode, X } from '@lucide/vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminReadOnlyTable from '../components/admin/AdminReadOnlyTable.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { qrImages } from '../lib/qrImage'

const { t, locale } = useI18n()
const rows = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(25)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const formError = ref('')
const editId = ref(null)
const label = ref('')
const destination = ref('')
const selected = ref(null)
const image = ref(null)
const dialog = ref(null)
const editor = ref(null)
const statisticsDialog = ref(null)
const statistics = ref(null)
const statisticsLoading = ref(false)
const statisticsError = ref('')
const statisticsLink = ref(null)
let requestId = 0
let statisticsRequestId = 0
const columns = computed(() => ['label', 'destination', 'scans', 'lastScanAt', 'url', 'actions'].map((key) => ({ key, label: t(`adminQr.${key}`) })))
const labels = computed(() => ({ caption: t('adminQr.title'), empty: t('adminQr.empty'), pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'), previous: t('adminDashboard.previous'), next: t('adminDashboard.next') }))

async function load() {
  if (!currentUser.value?.isAdmin) return
  const ticket = ++requestId
  loading.value = true
  error.value = ''
  try {
    const result = await apiGet(`/admin/qr-links?page=${page.value}&pageSize=${pageSize.value}`)
    if (ticket !== requestId) return
    rows.value = result.data
    total.value = result.meta.total
    page.value = result.meta.page
  } catch (cause) {
    if (ticket === requestId) error.value = cause.message || t('adminQr.loadError')
  } finally {
    if (ticket === requestId) loading.value = false
  }
}

function edit(row) {
  formError.value = ''
  editId.value = row.id
  label.value = row.label
  destination.value = row.destination
  editor.value?.showModal()
}
function reset() {
  formError.value = ''
  editId.value = null
  label.value = ''
  destination.value = ''
}
function create() {
  reset()
  editor.value?.showModal()
}
function closeEditor() {
  editor.value?.close()
  reset()
}
function dismissEditor() {
  if (!saving.value) closeEditor()
}
async function preview(row) {
  selected.value = row
  image.value = null
  try {
    image.value = await qrImages(row.url)
    dialog.value.showModal()
  } catch {
    error.value = t('adminQr.imageError')
  }
}
async function save() {
  if (saving.value) return
  saving.value = true
  formError.value = ''
  try {
    const result = await apiRequest(`/admin/qr-links${editId.value === null ? '' : `/${editId.value}`}`, { method: editId.value === null ? 'POST' : 'PUT', body: { label: label.value, destination: destination.value } })
    closeEditor()
    page.value = 1
    await load()
    await preview(result.data)
  } catch (cause) {
    formError.value = cause.message || t('adminQr.saveError')
  } finally {
    saving.value = false
  }
}
function download(format) {
  if (!image.value || !selected.value) return
  const url = format === 'svg' ? URL.createObjectURL(new Blob([image.value.svg], { type: 'image/svg+xml' })) : image.value.png
  const link = document.createElement('a')
  link.href = url
  link.download = `wave-qr-${selected.value.id}.${format}`
  link.click()
  if (format === 'svg') setTimeout(() => URL.revokeObjectURL(url), 1000)
}
function navigate(target) {
  if (loading.value) return
  page.value = target
  load()
}
function date(value) {
  if (!value) return '—'
  const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
  return `${formatDate(value)} ${time}`
}
function locationName(row) {
  const country = row.country ? new Intl.DisplayNames([locale.value], { type: 'region' }).of(row.country) : ''
  return [row.city, country].filter(Boolean).join(', ') || t('adminQr.unknownLocation')
}
async function showStatistics(row) {
  const ticket = ++statisticsRequestId
  statisticsLink.value = row
  statistics.value = null
  statisticsError.value = ''
  statisticsLoading.value = true
  statisticsDialog.value?.showModal()
  try {
    const result = await apiGet(`/admin/qr-links/${row.id}/statistics`)
    if (ticket === statisticsRequestId) statistics.value = result.data
  } catch (cause) {
    if (ticket === statisticsRequestId) statisticsError.value = cause.message || t('adminQr.statisticsError')
  } finally {
    if (ticket === statisticsRequestId) statisticsLoading.value = false
  }
}
watch(pageSize, () => { page.value = 1; load() })
watch([locale, () => currentUser.value?.isAdmin], load)
onMounted(load)
</script>

<template>
  <AdminPage>
    <div v-if="currentUser?.isAdmin" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main admin-qr">
        <header class="admin-dashboard__heading"><h1>{{ t('adminQr.title') }}</h1><p>{{ t('adminQr.description') }}</p></header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <dialog
          ref="editor"
          class="admin-qr__dialog admin-qr__editor"
          aria-labelledby="qr-editor-title"
          aria-describedby="qr-editor-hint"
          @cancel.prevent="dismissEditor"
          @click.self="dismissEditor"
        >
          <form class="admin-qr__form" :aria-busy="saving" @submit.prevent="save">
            <h2 id="qr-editor-title">{{ t(editId === null ? 'adminQr.create' : 'adminQr.edit') }}</h2>
            <StatusMessage v-if="formError" variant="error">{{ formError }}</StatusMessage>
            <label class="form-field" for="qr-label">
              <span>{{ t('adminQr.label') }}</span>
              <input id="qr-label" v-model="label" required maxlength="120" :disabled="saving" />
            </label>
            <label class="form-field" for="qr-destination">
              <span>{{ t('adminQr.destination') }}</span>
              <input id="qr-destination" v-model="destination" type="url" required maxlength="2048" placeholder="https://wave.ba/kampanje" :disabled="saving" />
            </label>
            <p id="qr-editor-hint">{{ t('adminQr.hint') }}</p>
            <div class="admin-qr__actions">
              <button type="button" class="button button--outline" :disabled="saving" @click="dismissEditor">{{ t('adminQr.cancel') }}</button>
              <button type="submit" class="button button--dark" :disabled="saving">{{ t(saving ? 'adminQr.saving' : editId === null ? 'adminQr.generate' : 'adminQr.save') }}</button>
            </div>
          </form>
        </dialog>
        <AdminReadOnlyTable :rows="rows" :columns="columns" :labels="labels" :total="total" :page="page" :page-size="pageSize" :busy="loading" @page="navigate" @page-size="pageSize = $event">
          <template #toolbar><div class="admin-qr__toolbar"><button type="button" class="button button--dark" @click="create">{{ t('adminQr.create') }}</button></div></template>
          <template #cell-destination="{ value }"><a :href="value" target="_blank" rel="noopener noreferrer">{{ value }}</a></template>
          <template #cell-url="{ value }"><a :href="value" target="_blank" rel="noopener noreferrer">{{ value }}</a></template>
          <template #cell-scans="{ row }"><button type="button" class="text-link admin-qr__scan-count" :aria-label="t('adminQr.viewStatistics', { label: row.label, count: row.scans || 0 })" @click="showStatistics(row)">{{ row.scans || 0 }}</button></template>
          <template #cell-lastScanAt="{ value }">{{ date(value) }}</template>
          <template #cell-actions="{ row }">
            <AdminRowActions :label="t('adminDashboard.rowActions', { name: row.label })">
              <button type="button" role="menuitem" class="admin-row-actions__item" @click="edit(row)"><Pencil :size="16" aria-hidden="true" />{{ t('adminQr.edit') }}</button>
              <button type="button" role="menuitem" class="admin-row-actions__item" @click="showStatistics(row)"><BarChart3 :size="16" aria-hidden="true" />{{ t('adminQr.statistics') }}</button>
              <button type="button" role="menuitem" class="admin-row-actions__item" @click="preview(row)"><QrCode :size="16" aria-hidden="true" />{{ t('adminQr.show') }}</button>
            </AdminRowActions>
          </template>
        </AdminReadOnlyTable>
        <dialog
          ref="statisticsDialog"
          class="admin-qr__dialog admin-qr__statistics"
          aria-labelledby="qr-statistics-title"
          aria-describedby="qr-statistics-hint"
          @click.self="statisticsDialog.close()"
        >
          <header class="admin-qr__statistics-header">
            <div>
              <h2 id="qr-statistics-title">{{ t('adminQr.statistics') }} · {{ statisticsLink?.label }}</h2>
              <p id="qr-statistics-hint">{{ t('adminQr.statisticsHint') }}</p>
            </div>
            <button type="button" class="admin-qr__close" :aria-label="t('adminQr.close')" @click="statisticsDialog.close()"><X :size="22" aria-hidden="true" /></button>
          </header>
          <div class="admin-qr__statistics-body" :aria-busy="statisticsLoading">
            <StatusMessage v-if="statisticsError" variant="error">{{ statisticsError }}</StatusMessage>
            <p v-else-if="statisticsLoading" role="status">{{ t('adminQr.statisticsLoading') }}</p>
            <template v-else-if="statistics">
              <dl class="admin-qr__stat-summary">
                <div>
                  <dt>{{ t('adminQr.scans') }}</dt>
                  <dd>{{ statistics.scans }}</dd>
                  <dd class="admin-qr__stat-help">{{ t('adminQr.scansHint') }}</dd>
                </div>
                <div>
                  <dt>{{ t('adminQr.lastScanAt') }}</dt>
                  <dd class="admin-qr__stat-date"><time v-if="statistics.lastScanAt" :datetime="statistics.lastScanAt">{{ date(statistics.lastScanAt) }}</time><span v-else>—</span></dd>
                  <dd class="admin-qr__stat-help">{{ t('adminQr.lastScanHint') }}</dd>
                </div>
              </dl>
              <section class="admin-qr__stat-section" aria-labelledby="qr-locations-title">
                <h3 id="qr-locations-title">{{ t('adminQr.locations') }}</h3>
                <p>{{ t('adminQr.locationHint') }}</p>
                <p v-if="!statistics.locations.length" class="admin-qr__stat-empty">{{ t('adminQr.noScans') }}</p>
                <div v-else class="admin-qr__stat-table-wrap">
                  <table class="admin-qr__stat-table">
                    <caption class="sr-only">{{ t('adminQr.locations') }}</caption>
                    <thead><tr><th scope="col">{{ t('adminQr.location') }}</th><th scope="col">{{ t('adminQr.scans') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="row in statistics.locations" :key="`${row.country}-${row.city}`">
                        <td><span class="admin-qr__stat-cell"><MapPin :size="18" aria-hidden="true" />{{ locationName(row) }}</span></td>
                        <td>{{ row.scans }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>
              <section class="admin-qr__stat-section" aria-labelledby="qr-daily-title">
                <h3 id="qr-daily-title">{{ t('adminQr.daily') }}</h3>
                <p>{{ t('adminQr.dailyHint') }}</p>
                <p v-if="!statistics.daily.length" class="admin-qr__stat-empty">{{ t('adminQr.noRecentScans') }}</p>
                <div v-else class="admin-qr__stat-table-wrap">
                  <table class="admin-qr__stat-table">
                    <caption class="sr-only">{{ t('adminQr.daily') }}</caption>
                    <thead><tr><th scope="col">{{ t('adminQr.day') }}</th><th scope="col">{{ t('adminQr.scans') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="row in statistics.daily" :key="row.day">
                        <td><span class="admin-qr__stat-cell"><CalendarDays :size="18" aria-hidden="true" /><time :datetime="row.day">{{ formatDate(row.day) }}</time></span></td>
                        <td>{{ row.scans }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>
            </template>
          </div>
          <footer class="admin-qr__statistics-footer">
            <button type="button" class="button button--outline" @click="statisticsDialog.close()">{{ t('adminQr.close') }}</button>
          </footer>
        </dialog>
        <dialog ref="dialog" class="admin-qr__dialog" aria-labelledby="qr-preview-title" @click="($event.target === dialog) && dialog.close()">
          <h2 id="qr-preview-title">{{ selected?.label }}</h2>
          <img v-if="image" :src="image.png" :alt="t('adminQr.imageAlt', { label: selected?.label })" width="280" height="280" />
          <p class="admin-qr__url">{{ selected?.url }}</p>
          <p>{{ t('adminQr.permanent') }}</p>
          <div class="admin-qr__actions"><button type="button" class="button button--dark" @click="download('svg')">{{ t('adminQr.downloadSvg') }}</button><button type="button" class="button button--outline" @click="download('png')">{{ t('adminQr.downloadPng') }}</button></div>
          <button type="button" class="button button--outline" @click="dialog.close()">{{ t('adminQr.close') }}</button>
        </dialog>
      </main>
    </div>
    <div v-else class="admin-dashboard__state"><StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage><LocalizedLink :to="{ name: 'account' }">{{ t('app.account') }}</LocalizedLink></div>
  </AdminPage>
</template>

<style scoped lang="scss" src="../scss/views/AdminQrView.scss"></style>
