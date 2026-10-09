<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RefreshCw, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminReadOnlyTable from '../components/admin/AdminReadOnlyTable.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, formatDate } from '../lib/api'

const { locale, t } = useI18n()
const access = ref('loading')
const busy = ref(false)
const error = ref('')
const rows = ref([])
const page = ref(1)
const pageSize = ref(25)
const total = ref(0)
const selected = ref(null)
const dialog = ref(null)
let requestVersion = 0
const columns = computed(() => [
  { key: 'number', label: t('support.number') },
  { key: 'title', label: t('support.title') },
  { key: 'name', label: t('support.name') },
  { key: 'email', label: t('support.email') },
  { key: 'kind', label: t('support.kind') },
  { key: 'category', label: t('support.category') },
  { key: 'createdAt', label: t('support.createdAt') },
  { key: 'status', label: t('support.status') },
  { key: 'actions', label: t('adminDashboard.actions') },
])
const labels = computed(() => ({ caption: t('support.adminTitle'), empty: t('support.empty'), pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'), previous: t('adminDashboard.previous'), next: t('adminDashboard.next') }))
async function load() {
  const version = ++requestVersion
  busy.value = true
  error.value = ''
  try {
    const response = await apiGet(`/admin/support-tickets?page=${page.value}&pageSize=${pageSize.value}`)
    if (version !== requestVersion) return
    rows.value = response.data
    total.value = response.meta.total
    page.value = response.meta.page
    access.value = 'allowed'
  } catch (cause) {
    if (version !== requestVersion) return
    rows.value = []
    total.value = 0
    access.value = cause.status === 401 ? 'anonymous' : cause.status === 403 ? 'forbidden' : 'error'
    error.value = cause.message
  } finally {
    if (version === requestVersion) busy.value = false
  }
}
function navigate(target) {
  if (busy.value || target < 1 || target > Math.max(1, Math.ceil(total.value / pageSize.value))) return
  page.value = target
  load()
}
async function view(row) {
  selected.value = row
  await nextTick()
  dialog.value.showModal()
}
watch([locale, pageSize], () => { page.value = 1; load() })
watch(() => currentUser.value?.id, () => { selected.value = null; dialog.value?.close(); access.value = 'loading'; page.value = 1; load() })
onMounted(load)
onBeforeUnmount(() => { requestVersion++ })
</script>

<template>
  <AdminPage>
    <div v-if="access === 'loading'" class="admin-dashboard__layout"><AccountSidebar v-if="currentUser" :user="currentUser" admin-layout /><main class="admin-dashboard__main"><LoadingSkeleton variant="dashboard" :label="t('adminDashboard.loading')" /></main></div>
    <div v-else-if="access === 'anonymous'" class="admin-dashboard__state"><StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage><LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">{{ t('auth.signIn') }}</LocalizedLink></div>
    <div v-else-if="access === 'forbidden'" class="admin-dashboard__state"><StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage></div>
    <div v-else class="admin-dashboard__layout"><AccountSidebar :user="currentUser" admin-layout /><main class="admin-dashboard__main admin-support">
      <header class="admin-dashboard__heading"><h1>{{ t('support.adminTitle') }}</h1><p>{{ t('support.adminIntro') }}</p></header>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <AdminReadOnlyTable :columns="columns" :rows="rows" :labels="labels" :page="page" :page-size="pageSize" :total="total" :busy="busy" :show-empty="!error" @page="navigate" @page-size="pageSize = $event">
        <template #toolbar><button class="admin-support__refresh" type="button" :disabled="busy" :aria-label="t('adminTools.refresh')" :title="t('adminTools.refresh')" @click="load"><RefreshCw :size="18" aria-hidden="true" /></button></template>
        <template #cell-number="{ value }">#{{ value }}</template><template #cell-kind="{ value }">{{ t(`support.${value}`) }}</template><template #cell-category="{ value }">{{ t(`support.categories.${value}`) }}</template><template #cell-createdAt="{ value }">{{ formatDate(value) }}</template><template #cell-status>{{ t('support.received') }}</template>
        <template #cell-actions="{ row }"><AdminRowActions :label="t('support.view')"><template #default><button type="button" role="menuitem" @click="view(row)">{{ t('support.view') }}</button></template></AdminRowActions></template>
      </AdminReadOnlyTable>
      <dialog ref="dialog" class="admin-support__dialog" aria-labelledby="support-ticket-detail" @click.self="dialog.close()">
        <template v-if="selected"><header><div><p class="eyebrow">#{{ selected.number }} · {{ formatDate(selected.createdAt) }}</p><h2 id="support-ticket-detail">{{ selected.title }}</h2></div><button type="button" :aria-label="t('support.close')" @click="dialog.close()"><X :size="20" aria-hidden="true" /></button></header>
          <dl><div><dt>{{ t('support.name') }}</dt><dd>{{ selected.name }}</dd></div><div><dt>{{ t('support.email') }}</dt><dd>{{ selected.email }}</dd></div><div v-if="selected.phone"><dt>{{ t('support.phone') }}</dt><dd>{{ selected.phone }}</dd></div><div><dt>{{ t('support.kind') }}</dt><dd>{{ t(`support.${selected.kind}`) }}</dd></div><div><dt>{{ t('support.category') }}</dt><dd>{{ t(`support.categories.${selected.category}`) }}</dd></div></dl>
          <p class="admin-support__description">{{ selected.description }}</p><h3>{{ t('support.attachments') }}</h3><ul v-if="selected.attachments.length"><li v-for="(file, index) in selected.attachments" :key="index"><a :href="`${file.url}?locale=${locale}`" download>{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</a></li></ul><p v-else>{{ t('support.noAttachments') }}</p>
          <footer><button type="button" class="button button--outline" @click="dialog.close()">{{ t('support.close') }}</button></footer>
        </template>
      </dialog>
    </main></div>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminSupportView.scss"></style>
