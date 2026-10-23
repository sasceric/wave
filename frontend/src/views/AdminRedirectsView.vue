<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pencil, Plus, Power, Trash2, X } from '@lucide/vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { localizedPath } from '../routePaths'

const { t, locale } = useI18n()
const rows = ref([])
const total = ref(0)
const page = ref(1)
const listOptions = ref({ page: 1, limit: 25, sort: 'sourcePath', direction: 'asc' })
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const formError = ref('')
const editor = ref(null)
const editId = ref(null)
const sourcePath = ref('')
const targetPath = ref('')
const enabled = ref(true)
const preserveQuery = ref(false)
const deleting = ref(null)
let version = 0
let searchTimer
const columns = computed(() => ['sourcePath', 'targetPath', 'statusCode', 'status', 'origin', 'updatedAt'].map(key => ({ key, label: t(`adminRedirects.${key}`), sortable: key !== 'statusCode' })))
const labels = computed(() => ({
  caption: t('adminRedirects.title'), empty: t('adminRedirects.empty'),
  loading: t('adminDashboard.loading'), actions: t('adminDashboard.actions'),
  deleteSelected: t('adminDashboard.deleteSelected'),
  pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'),
  previous: t('adminDashboard.previous'), next: t('adminDashboard.next'),
  goToPage: page => t('adminDashboard.goToPage', { page }),
  selectPage: t('adminDashboard.selectPage'),
  selectRow: name => t('adminDashboard.selectRow', { name }),
  selected: count => t('adminDashboard.selected', { count }),
}))
const deleteMessage = computed(() => `${t('adminRedirects.deleteMessage')} ${t('adminDashboard.selected', { count: deleting.value?.ids.length || 0 })}`)

async function load() {
  if (!currentUser.value?.isAdmin) return
  const ticket = ++version
  loading.value = true
  error.value = ''
  try {
    const result = await apiGet(`/admin/redirects?${new URLSearchParams({ ...listOptions.value, page: page.value, search: search.value })}`)
    if (ticket !== version) return
    rows.value = result.data
    total.value = result.meta.total
    page.value = result.meta.page
  } catch (cause) {
    if (ticket === version) error.value = cause.message || t('adminRedirects.loadError')
  } finally {
    if (ticket === version) loading.value = false
  }
}

function edit(row = null) {
  formError.value = ''
  editId.value = row?.id ?? null
  sourcePath.value = row?.sourcePath || ''
  targetPath.value = row?.targetPath || ''
  enabled.value = row?.enabled ?? true
  preserveQuery.value = row?.preserveQuery ?? false
  editor.value?.showModal()
}
function closeEditor() {
  if (!saving.value) editor.value?.close()
}
async function save() {
  if (saving.value) return
  saving.value = true
  formError.value = ''
  try {
    await apiRequest(`/admin/redirects${editId.value === null ? '' : `/${editId.value}`}`, { method: editId.value === null ? 'POST' : 'PUT', body: { sourcePath: sourcePath.value, targetPath: targetPath.value, enabled: enabled.value, preserveQuery: preserveQuery.value } })
    editor.value?.close()
    await load()
  } catch {
    formError.value = t('adminRedirects.invalid')
  } finally {
    saving.value = false
  }
}
async function toggle(row) {
  if (saving.value) return
  saving.value = true
  error.value = ''
  try {
    await apiRequest(`/admin/redirects/${row.id}`, { method: 'PUT', body: { sourcePath: row.sourcePath, targetPath: row.targetPath, enabled: !row.enabled, preserveQuery: row.preserveQuery } })
    await load()
  } catch {
    error.value = t('adminRedirects.invalid')
  } finally {
    saving.value = false
  }
}
function requestDelete(ids) {
  if (!saving.value && ids.length) deleting.value = { ids: [...ids] }
}
async function remove() {
  if (saving.value || !deleting.value) return
  saving.value = true
  try {
    await apiRequest('/admin/redirects/bulk-delete', { method: 'DELETE', body: { ids: deleting.value.ids } })
    deleting.value = null
    await load()
  } catch (cause) {
    error.value = cause.message
  } finally {
    saving.value = false
  }
}
function changeList(options) {
  listOptions.value = options
  page.value = options.page
  return load()
}
watch(search, () => {
  clearTimeout(searchTimer)
  ++version
  searchTimer = setTimeout(() => { page.value = 1; load() }, 250)
})
watch([locale, () => currentUser.value?.isAdmin], load)
onMounted(load)
onBeforeUnmount(() => { clearTimeout(searchTimer); ++version })
</script>

<template>
  <AdminPage>
    <div v-if="currentUser?.isAdmin" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" admin-layout />
      <main class="admin-dashboard__main admin-redirects">
        <header class="admin-dashboard__heading"><h1>{{ t('adminRedirects.title') }}</h1><p>{{ t('adminRedirects.description') }}</p></header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <AdminDataTable :columns="columns" :rows="rows" :labels="labels" :current-page="page" :total="total" :loading="loading || saving" @change="changeList" @delete-selected="requestDelete">
          <template #toolbar>
            <div class="admin-redirects__toolbar">
              <input v-model.trim="search" type="search" :placeholder="t('adminRedirects.search')" :aria-label="t('adminRedirects.search')" />
              <button class="button button--dark" type="button" @click="edit()"><Plus :size="16" aria-hidden="true" />{{ t('adminRedirects.create') }}</button>
            </div>
          </template>
          <template #cell-sourcePath="{ value }"><code>{{ value }}</code></template>
          <template #cell-targetPath="{ value }"><a :href="value" target="_blank" rel="noopener">{{ value }}</a></template>
          <template #cell-statusCode>301</template>
          <template #cell-status="{ row }"><span class="admin-redirects__status" :class="{ 'is-disabled': !row.enabled }">{{ t(`adminRedirects.${row.enabled ? 'enabled' : 'disabled'}`) }}</span></template>
          <template #cell-origin="{ value }">{{ t(`adminRedirects.${value}`) }}</template>
          <template #cell-updatedAt="{ value }">{{ formatDate(value) }}</template>
          <template #actions="{ row }">
            <AdminRowActions :label="`${t('adminRedirects.actions')}: ${row.sourcePath}`">
              <button type="button" role="menuitem" :disabled="saving" @click="edit(row)"><Pencil :size="15" aria-hidden="true" />{{ t('adminRedirects.edit') }}</button>
              <button type="button" role="menuitem" :disabled="saving" @click="toggle(row)"><Power :size="15" aria-hidden="true" />{{ t(`adminRedirects.${row.enabled ? 'disable' : 'enable'}`) }}</button>
              <button type="button" role="menuitem" :disabled="saving" @click="requestDelete([row.id])"><Trash2 :size="15" aria-hidden="true" />{{ t('adminRedirects.delete') }}</button>
            </AdminRowActions>
          </template>
        </AdminDataTable>
        <dialog ref="editor" class="admin-redirects__editor wave-modal wave-modal--padded" aria-labelledby="redirect-editor-title" aria-describedby="redirect-editor-hint" @cancel.prevent="closeEditor" @click.self="closeEditor" aria-modal="true">
          <header class="wave-modal__header"><h2 id="redirect-editor-title" class="wave-modal__title">{{ t(`adminRedirects.${editId === null ? 'create' : 'edit'}`) }}</h2><button class="wave-modal__close" type="button" :disabled="saving" :aria-label="t('adminRedirects.cancel')" @click="closeEditor"><X :size="20" aria-hidden="true" /></button></header>
          <p id="redirect-editor-hint">{{ t('adminRedirects.hint') }}</p>
          <form v-form-validation @submit.prevent="save">
            <StatusMessage v-if="formError" variant="error">{{ formError }}</StatusMessage>
            <label><span>{{ t('adminRedirects.sourcePath') }}</span><input v-model.trim="sourcePath" required maxlength="500" placeholder="/stari-link" autocomplete="off" spellcheck="false" autofocus /></label>
            <label><span>{{ t('adminRedirects.targetPath') }}</span><input v-model.trim="targetPath" required maxlength="1000" :placeholder="localizedPath('creators', locale)" autocomplete="off" spellcheck="false" /></label>
            <label class="admin-redirects__check"><input v-model="enabled" type="checkbox" />{{ t('adminRedirects.enabled') }}</label>
            <label class="admin-redirects__check"><input v-model="preserveQuery" type="checkbox" />{{ t('adminRedirects.preserveQuery') }}</label>
            <footer><button class="button button--outline" type="button" :disabled="saving" @click="closeEditor">{{ t('adminRedirects.cancel') }}</button><button class="button button--dark" type="submit" :disabled="saving">{{ t('adminRedirects.save') }}{{ saving ? '…' : '' }}</button></footer>
          </form>
        </dialog>
        <ConfirmationModal :open="Boolean(deleting)" :title="t(`adminRedirects.${deleting?.ids.length > 1 ? 'deleteBulkTitle' : 'deleteTitle'}`)" :message="deleteMessage" :confirm-label="t('adminRedirects.delete')" :cancel-label="t('adminRedirects.cancel')" :loading="saving" @update:open="!$event && !saving && (deleting = null)" @confirm="remove" />
      </main>
    </div>
    <main v-else class="admin-dashboard__main"><LocalizedLink :to="{ name: 'account' }" class="button button--dark">{{ t('auth.signIn') }}</LocalizedLink></main>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminRedirectsView.scss"></style>
