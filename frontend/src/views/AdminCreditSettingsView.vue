<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Coins, Copy, KeyRound, Settings2, Trash2, UserRoundPlus } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import CreditRecipientSelect from '../components/admin/CreditRecipientSelect.vue'
import FormModal from '../components/shared/FormModal.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import { currentUser } from '../composables/useCurrentUser'
import { refreshCredits } from '../composables/useCredits'
import { apiGet, apiRequest, formatDate } from '../lib/api'

const { t, locale } = useI18n()
const saved = ref(null)
const form = ref(null)
const price = ref(0.2)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const confirming = ref(false)
const deleteIds = ref([])
const assigning = ref(null)
const recipientType = ref('')
const recipientId = ref(null)
const assignmentError = ref('')
const vouchersLoading = ref(false)
const listOptions = ref({ limit: 25, sort: 'created_at', direction: 'desc' })
let vouchersRevision = 0
const pack = ref(50)
const issued = ref(null)
const vouchers = ref({ rows: [], page: 1, total: 0 })
const page = ref(1)
const switchValue = computed({ get: () => Number(form.value?.paid), set: (value) => { form.value.paid = value === 1 } })
const activation = computed(() => form.value?.paid && !saved.value?.paid)

function money(minor) {
  return `${new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)} KM`
}
const columns = computed(() => [
  { key: 'code', label: t('credits.code') },
  { key: 'amount', label: t('credits.pack'), sortable: true },
  { key: 'created_at', label: t('credits.date'), sortable: true, defaultSort: true },
  { key: 'status', label: t('credits.status'), sortable: true },
  { key: 'recipient', label: t('credits.assignedTo'), sortable: true },
])
const tableLabels = computed(() => ({
  caption: t('credits.voucherHistory'), loading: t('adminDashboard.loading'), actions: t('credits.actions'),
  empty: t('credits.emptyVouchers'), deleteSelected: t('adminDashboard.deleteSelected'),
  pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'),
  previous: t('adminDashboard.previous'), next: t('adminDashboard.next'),
  goToPage: value => t('adminDashboard.goToPage', { page: value }),
  selectPage: t('adminDashboard.selectPage'), selectRow: name => t('adminDashboard.selectRow', { name }),
  selected: count => t('adminDashboard.selected', { count }),
}))
async function loadVouchers(options) {
  if (options) { page.value = options.page; listOptions.value = { limit: options.limit, sort: options.sort, direction: options.direction } }
  const revision = ++vouchersRevision
  vouchersLoading.value = true
  try {
    const { data } = await apiGet(`/admin/credits/vouchers?${new URLSearchParams({ page: page.value, ...listOptions.value })}`)
    if (revision !== vouchersRevision) return
    vouchers.value = data
    page.value = data.page
  } catch (cause) {
    if (revision !== vouchersRevision) return
    if ([401, 403].includes(cause.status)) vouchers.value = { rows: [], page: 1, total: 0 }
    error.value = cause.message
  } finally {
    if (revision === vouchersRevision) vouchersLoading.value = false
  }
}
async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiGet('/admin/credits/settings')
    saved.value = data
    form.value = { ...data }
    price.value = data.unitPriceMinor / 100
    await loadVouchers()
  } catch (cause) {
    error.value = cause.message
  } finally {
    loading.value = false
  }
}
function submit() {
  notice.value = ''
  error.value = ''
  if (activation.value) confirming.value = true
  else save()
}
async function save() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const { data } = await apiRequest('/admin/credits/settings', { method: 'PUT', body: { ...form.value, unitPriceMinor: Math.round(Number(price.value) * 100) } })
    saved.value = data
    form.value = { ...data }
    price.value = data.unitPriceMinor / 100
    notice.value = t('credits.settingsSaved')
    confirming.value = false
    refreshCredits().catch(() => {})
  } catch (cause) {
    error.value = cause.message
    confirming.value = false
  } finally {
    busy.value = false
  }
}
async function issue() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  issued.value = null
  try {
    const { data } = await apiRequest('/admin/credits/vouchers', { method: 'POST', body: { amount: Number(pack.value) } })
    issued.value = data
    page.value = 1
    await loadVouchers()
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = false
  }
}
async function copyCode() {
  try {
    await navigator.clipboard.writeText(issued.value.code)
    notice.value = t('credits.copied')
  } catch {
    notice.value = t('credits.copyManually')
  }
}
async function deleteVouchers() {
  if (busy.value || !deleteIds.value.length) return
  busy.value = true
  error.value = ''
  try {
    await apiRequest('/admin/credits/vouchers', { method: 'DELETE', body: { ids: [...deleteIds.value] } })
    deleteIds.value = []
    await loadVouchers()
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
function openAssignment(voucher) {
  assigning.value = voucher
  recipientType.value = ''
  recipientId.value = null
  assignmentError.value = ''
}
function closeAssignment() {
  assigning.value = null
  recipientId.value = null
  recipientType.value = ''
  assignmentError.value = ''
}
async function assign() {
  if (busy.value || !assigning.value || !recipientType.value || !Number.isInteger(recipientId.value)) return
  busy.value = true
  assignmentError.value = ''
  try {
    await apiRequest(`/admin/credits/vouchers/${assigning.value.id}/assign`, { method: 'POST', body: { accountType: recipientType.value, userId: recipientId.value } })
    closeAssignment()
    notice.value = t('credits.assignmentSuccess')
    await loadVouchers()
  } catch (cause) { assignmentError.value = cause.message }
  finally { busy.value = false }
}
watch(recipientType, () => { recipientId.value = null })
onBeforeUnmount(() => { ++vouchersRevision })
watch(locale, load)
onMounted(load)
</script>

<template>
  <AdminPage class="credits-page">
    <div class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" admin-layout />
      <main class="admin-dashboard__main credits-page__main">
        <header class="credits-heading"><p class="eyebrow">{{ t('adminDashboard.navOverview') }}</p><h1>{{ t('credits.adminTitle') }}</h1><p>{{ t('credits.adminIntro') }}</p></header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <StatusMessage v-if="notice" variant="success">{{ notice }}</StatusMessage>
        <LoadingSkeleton v-if="loading && !form" variant="dashboard" :label="t('adminDashboard.loading')" />
        <template v-if="form && currentUser?.isAdmin">
          <form v-form-validation class="credit-card credits-settings" @submit.prevent="submit">
            <header><h2><Settings2 :size="21" aria-hidden="true" />{{ t('credits.modeAndPricing') }}</h2><span class="credits-mode">{{ saved.paid ? t('credits.paidMode') : t('credits.freeMode') }}</span></header>
            <SwitchField v-model="switchValue" :label="t('credits.paidSwitch')" :description="t('credits.switchHint')" :disabled="busy" />
            <div class="credits-settings__fields">
              <label class="form-field"><span>{{ t('credits.price') }}</span><input v-model.number="price" type="number" min="0.01" max="1000" step="0.01" required :disabled="busy" /></label>
              <label class="form-field"><span>{{ t('credits.applicationCost') }}</span><input v-model.number="form.applicationCost" type="number" min="1" max="100000" step="1" required :disabled="busy" /></label>
              <label class="form-field"><span>{{ t('credits.campaignCost') }}</span><input v-model.number="form.campaignCost" type="number" min="1" max="100000" step="1" required :disabled="busy" /></label>
              <label class="form-field"><span>{{ t('credits.welcomeGrant') }}</span><input v-model.number="form.welcomeGrant" type="number" min="0" max="100000" step="1" required :disabled="busy" /><small>{{ t('credits.grantHint') }}</small></label>
            </div>
            <p class="credits-settings__note">{{ t('credits.activationHint') }}</p>
            <div class="credits-settings__footer"><LocalizedLink :to="{ name: 'admin-email-templates' }">{{ t('credits.emailTemplate') }}</LocalizedLink><button class="button button--dark" type="submit" :disabled="busy">{{ t('credits.saveSettings') }}</button></div>
          </form>
          <section class="credit-card credits-settings">
            <header><h2><KeyRound :size="21" aria-hidden="true" />{{ t('credits.issueCodes') }}</h2></header>
            <p>{{ t('credits.issueHint') }}</p>
            <form class="credits-redeem" @submit.prevent="issue"><SingleSelect v-model="pack" :label="t('credits.pack')" :options="saved.packs.map(item => ({ value: item.amount, label: `${item.amount} ${t('credits.unit')} · ${money(item.priceMinor)}` }))" /><button class="button button--dark" type="submit" :disabled="busy"><Coins :size="17" aria-hidden="true" />{{ t('credits.generate') }}</button></form>
            <div v-if="issued" class="credits-issued" role="status"><p>{{ t('credits.codeReady', { count: issued.amount, price: money(issued.priceMinor) }) }}</p><div><strong>{{ issued.code }}</strong><button type="button" class="button button--outline" @click="copyCode"><Copy :size="16" aria-hidden="true" />{{ t('credits.copy') }}</button></div><small>{{ t('credits.codeOnce') }}</small></div>
          </section>
          <section class="credits-voucher-history">
            <h2>{{ t('credits.voucherHistory') }}</h2>
            <AdminDataTable :columns="columns" :rows="vouchers.rows" :total="vouchers.total" :current-page="page" :page-size="listOptions.limit" initial-sort-direction="desc" :loading="vouchersLoading" :labels="tableLabels" @change="loadVouchers" @delete-selected="deleteIds = $event">
              <template #cell-code="{ row }"><code>{{ row.code || '••••' + row.suffix }}</code><small v-if="!row.code" class="credits-voucher-history__hint">{{ t('credits.legacyCode') }}</small></template>
              <template #cell-amount="{ row }">{{ row.amount }}<small class="credits-voucher-history__hint">{{ money(row.price_minor) }}</small></template>
              <template #cell-created_at="{ value }">{{ formatDate(value.replace(' ', 'T') + 'Z') }}</template>
              <template #cell-status="{ value }"><span class="credits-voucher-status" :class="`credits-voucher-status--${value}`">{{ t(`credits.voucherStatuses.${value}`) }}</span></template>
              <template #cell-recipient="{ row }">{{ row.recipient || '—' }}<small v-if="row.email" class="credits-voucher-history__hint">{{ row.email }}</small></template>
              <template #actions="{ row }"><AdminRowActions :label="t('adminDashboard.rowActions', { name: row.code || '••••' + row.suffix })">
                <button v-if="row.status === 'created'" class="admin-row-actions__item" role="menuitem" type="button" :disabled="busy || !row.code" :title="!row.code ? t('credits.legacyCode') : undefined" @click="openAssignment(row)"><UserRoundPlus :size="15" aria-hidden="true" />{{ t('credits.assign') }}</button>
                <button class="admin-row-actions__item admin-row-actions__item--danger" role="menuitem" type="button" :disabled="busy" @click="deleteIds = [row.id]"><Trash2 :size="15" aria-hidden="true" />{{ t('credits.deleteCode') }}</button>
              </AdminRowActions></template>
            </AdminDataTable>
          </section>
        </template>
      </main>
    </div>
    <ConfirmationModal v-model:open="confirming" :title="t('credits.enableTitle')" :message="t('credits.enableConfirm', { grant: form?.welcomeGrant ?? 0, apply: form?.applicationCost ?? 10, post: form?.campaignCost ?? 30 })" :confirm-label="t('credits.enable')" :cancel-label="t('credits.cancel')" :loading="busy" @confirm="save" />
    <ConfirmationModal :open="deleteIds.length > 0" :title="t('credits.deleteCode')" :message="t('credits.deleteConfirm', { count: deleteIds.length })" :confirm-label="t('credits.deleteCode')" :cancel-label="t('credits.cancel')" :loading="busy" @update:open="!$event && (deleteIds = [])" @confirm="deleteVouchers" />
    <FormModal :open="Boolean(assigning)" :title="t('credits.assign')" :description="t('credits.assignHint')" :busy="busy" @update:open="!$event && closeAssignment()" @submit="assign">
      <p v-if="assigning" class="credits-assignment-code"><code>{{ assigning.code }}</code> · {{ assigning.amount }} {{ t('credits.unit') }}</p>
      <SingleSelect v-model="recipientType" :label="t('credits.recipientType')" :options="[{ value: 'creator', label: t('adminDashboard.creators') }, { value: 'company', label: t('adminDashboard.companies') }]" :disabled="busy" required />
      <CreditRecipientSelect v-if="recipientType" :key="recipientType" v-model="recipientId" :account-type="recipientType" :disabled="busy" />
      <StatusMessage v-if="assignmentError" variant="error">{{ assignmentError }}</StatusMessage>
      <template #actions="{ close }"><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('credits.cancel') }}</button><button class="button button--dark" type="submit" :disabled="busy || !recipientId">{{ t('credits.assignAndEmail') }}</button></template>
    </FormModal>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/CreditsView.scss"></style>
