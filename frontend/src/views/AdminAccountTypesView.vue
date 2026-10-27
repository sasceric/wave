<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight } from '@lucide/vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import FormModal from '../components/shared/FormModal.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { currentUser } from '../composables/useCurrentUser'
const { t, locale } = useI18n()
const rows = ref([])
const total = ref(0)
const page = ref(1)
const limit = ref(25)
const status = ref('pending')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const selected = ref(null)
const response = ref('')
let generation = 0
const options = computed(() => ['pending', 'approved', 'rejected', 'withdrawn', 'all'].map(value => ({ value, label: t(`accountType.${value}`) })))
const columns = computed(() => ['account', 'change', 'status', 'createdAt'].map(key => ({ key, label: t(`accountType.${key}`), sortable: false })))
const labels = computed(() => ({ caption: t('accountType.adminTitle'), empty: t('accountType.empty'), loading: t('adminDashboard.loading'), actions: t('adminDashboard.actions'), pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'), previous: t('adminDashboard.previous'), next: t('adminDashboard.next'), goToPage: value => t('adminDashboard.goToPage', { page: value }) }))
watch([status, locale, () => currentUser.value?.isAdmin], () => { page.value = 1; load() }, { immediate: true })
onBeforeUnmount(() => { ++generation })
async function load() {
  if (!currentUser.value?.isAdmin) return
  const ticket = ++generation
  loading.value = true
  error.value = ''
  try {
    const { data, meta } = await apiGet(`/admin/account-type-requests?${new URLSearchParams({ status: status.value, page: page.value, limit: limit.value })}`)
    if (ticket === generation) { rows.value = data; total.value = meta.total }
  } catch (cause) { if (ticket === generation) error.value = cause.message }
  finally { if (ticket === generation) loading.value = false }
}
function review(row) { selected.value = row; response.value = row.response || '' }
async function decide(decision) {
  if (busy.value || !selected.value) return
  busy.value = true
  try {
    await apiRequest(`/admin/account-type-requests/${selected.value.id}`, { method: 'PUT', body: { decision, response: response.value }, feedback: { success: t(decision === 'approved' ? 'accountType.approvalDone' : 'accountType.rejectionDone') } })
    selected.value = null
    await load()
  } catch { /* Errors appear above this modal without losing the review. */ }
  finally { busy.value = false }
}
function change(options) { page.value = options.page; limit.value = options.limit; load() }
</script>

<template>
  <AdminPage><div v-if="currentUser?.isAdmin" class="admin-dashboard__layout"><AccountSidebar :user="currentUser" admin-layout /><main class="admin-dashboard__main account-type-admin">
    <header class="admin-dashboard__heading"><h1>{{ t('accountType.adminTitle') }}</h1><p>{{ t('accountType.adminIntro') }}</p></header>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <AdminDataTable :columns="columns" :rows="rows" :labels="labels" :total="total" :current-page="page" :loading="loading" :selectable="false" @change="change">
      <template #toolbar><SingleSelect v-model="status" :label="t('accountType.status')" :options="options" /></template>
      <template #cell-account="{ row }"><div class="account-type-admin__identity"><img v-if="row.account.imageUrl" :src="row.account.imageUrl" alt="" /><div><strong>{{ row.account.name || row.account.email }}</strong><small>{{ row.account.email }}</small></div></div></template>
      <template #cell-change="{ row }"><span class="account-type-admin__change">{{ t(`accountType.${row.sourceType}`) }}<ArrowRight :size="16" aria-hidden="true" />{{ t(`accountType.${row.targetType}`) }}</span></template>
      <template #cell-status="{ row }">{{ t(`accountType.${row.status}`) }}</template>
      <template #cell-createdAt="{ value }">{{ formatDate(value) }}</template>
      <template #actions="{ row }"><button class="button button--outline button--small" type="button" @click="review(row)">{{ t('accountType.review') }}</button></template>
    </AdminDataTable>
    <FormModal v-if="selected" :open="Boolean(selected)" :title="t('accountType.review')" :description="t('accountType.mergeHint')" :busy="busy" @update:open="!$event && (selected = null)" @submit="decide('approved')">
      <dl class="account-type-admin__facts"><div><dt>{{ t('accountType.account') }}</dt><dd>{{ selected.account.name }} · {{ selected.account.email }}</dd></div><div><dt>{{ t('accountType.change') }}</dt><dd>{{ t(`accountType.${selected.sourceType}`) }} / {{ t(`accountType.${selected.targetType}`) }}</dd></div><div><dt>{{ t('accountType.profileName') }}</dt><dd>{{ selected.profileName }}</dd></div><div v-if="selected.message"><dt>{{ t('accountType.message') }}</dt><dd>{{ selected.message }}</dd></div></dl>
      <label><span>{{ t('accountType.response') }}</span><textarea v-model.trim="response" rows="3" maxlength="1000" :disabled="busy || selected.status !== 'pending'" /></label>
      <p>{{ t('accountType.activeWorkHint') }}</p>
      <template #actions="{ close }"><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('listingAds.cancel') }}</button><button v-if="selected.status === 'pending'" class="button button--outline" type="button" :disabled="busy || response.trim().length < 5" @click="decide('rejected')">{{ t('accountType.reject') }}</button><button v-if="selected.status === 'pending'" class="button button--dark" type="submit" :disabled="busy">{{ t('accountType.approve') }}</button></template>
    </FormModal>
  </main></div></AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminAccountTypesView.scss"></style>
