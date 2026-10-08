<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { Coins, Copy, KeyRound, Settings2, Trash2 } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
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
const revokeId = ref('')
const pack = ref(50)
const issued = ref(null)
const vouchers = ref({ rows: [], page: 1, total: 0 })
const page = ref(1)
const switchValue = computed({ get: () => Number(form.value?.paid), set: (value) => { form.value.paid = value === 1 } })
const activation = computed(() => form.value?.paid && !saved.value?.paid)

function money(minor) {
  return `${new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)} KM`
}
async function loadVouchers() {
  const { data } = await apiGet(`/admin/credits/vouchers?page=${page.value}`)
  vouchers.value = data
  page.value = data.page
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
async function revoke() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await apiRequest(`/admin/credits/vouchers/${revokeId.value}/revoke`, { method: 'POST', body: {} })
    revokeId.value = ''
    await loadVouchers()
  } catch (cause) {
    error.value = cause.message
    revokeId.value = ''
  } finally {
    busy.value = false
  }
}
function navigatePage(value) {
  page.value = value
  loadVouchers().catch((cause) => { error.value = cause.message })
}
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
          <section class="credit-card credits-history"><header><h2>{{ t('credits.voucherHistory') }}</h2></header>
            <div v-if="vouchers.rows.length" class="credits-table-wrap"><table class="credits-table"><thead><tr><th>{{ t('credits.code') }}</th><th>{{ t('credits.pack') }}</th><th>{{ t('credits.date') }}</th><th>{{ t('credits.status') }}</th><th>{{ t('credits.redeemedBy') }}</th><th scope="col" class="credits-table__actions">{{ t('credits.actions') }}</th></tr></thead><tbody><tr v-for="voucher in vouchers.rows" :key="voucher.id"><td>••••{{ voucher.suffix }}</td><td>{{ voucher.amount }}<small>{{ money(voucher.price_minor) }}</small></td><td>{{ formatDate(voucher.created_at.replace(' ', 'T') + 'Z') }}</td><td>{{ voucher.revoked_at ? t('credits.revoked') : voucher.redeemed_at ? t('credits.redeemed') : t('credits.available') }}</td><td>{{ voucher.email || '—' }}</td><td class="credits-table__actions"><AdminRowActions v-if="!voucher.revoked_at && !voucher.redeemed_at" :label="t('adminDashboard.rowActions', { name: '••••' + voucher.suffix })"><button class="admin-row-actions__item admin-row-actions__item--danger" role="menuitem" type="button" :disabled="busy" @click="revokeId = voucher.id"><Trash2 :size="15" aria-hidden="true" />{{ t('credits.revoke') }}</button></AdminRowActions></td></tr></tbody></table></div>
            <StatusMessage v-else variant="empty">{{ t('credits.emptyVouchers') }}</StatusMessage>
            <DirectoryPagination :page="page" :page-size="10" :total="vouchers.total" :show-page-size="false" show-single-page @update:page="navigatePage" />
          </section>
        </template>
      </main>
    </div>
    <ConfirmationModal v-model:open="confirming" :title="t('credits.enableTitle')" :message="t('credits.enableConfirm', { grant: form?.welcomeGrant ?? 0, apply: form?.applicationCost ?? 10, post: form?.campaignCost ?? 30 })" :confirm-label="t('credits.enable')" :cancel-label="t('credits.cancel')" :loading="busy" @confirm="save" />
    <ConfirmationModal :open="Boolean(revokeId)" :title="t('credits.revoke')" :message="t('credits.revokeConfirm')" :confirm-label="t('credits.revoke')" :cancel-label="t('credits.cancel')" :loading="busy" @update:open="!$event && (revokeId = '')" @confirm="revoke" />
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/CreditsView.scss"></style>
