<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ArrowRight, Coins, Gift, Info, Plus, Send } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SuccessModal from '../components/shared/SuccessModal.vue'
import { currentUser } from '../composables/useCurrentUser'
import { setCreditAccount } from '../composables/useCredits'
import { apiGet, apiRequest, formatDate } from '../lib/api'

const { t, locale } = useI18n()
const account = ref(null)
const page = ref(1)
const code = ref('')
const busy = ref(false)
const loading = ref(true)
const error = ref('')
const redeemError = ref('')
const success = ref(false)
const added = ref(0)
const selectedAmount = ref(50)
const historyType = ref('all')
const historySection = ref(null)
const company = computed(() => currentUser.value?.accountType === 'company')
const nextRoute = computed(() => ({ name: company.value ? 'account-campaign-create' : 'campaigns' }))
const selectedPack = computed(() => account.value?.settings.packs.find(pack => pack.amount === selectedAmount.value))
const historyOptions = computed(() => [
  { value: 'all', label: t('credits.allTypes') },
  { value: 'added', label: t('credits.addedType') },
  { value: 'spent', label: t('credits.spentType') },
])
let ticket = 0

function money(minor) {
  return `${new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)} KM`
}
async function load() {
  const request = ++ticket
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiGet(`/me/credits?page=${page.value}&type=${historyType.value}`)
    if (request !== ticket) return
    account.value = data
    page.value = data.page
    setCreditAccount(data)
  } catch (cause) {
    if (request === ticket) error.value = cause.message
  } finally {
    if (request === ticket) loading.value = false
  }
}
async function redeem() {
  if (busy.value) return
  busy.value = true
  redeemError.value = ''
  try {
    const { data } = await apiRequest('/me/credits/redeem', { method: 'POST', body: { code: code.value } })
    ticket += 1
    loading.value = false
    account.value = data
    page.value = 1
    historyType.value = 'all'
    setCreditAccount(data)
    added.value = data.added
    code.value = ''
    success.value = true
  } catch (cause) {
    redeemError.value = cause.message
  } finally {
    busy.value = false
  }
}
function showHistory() {
  historySection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  historySection.value?.focus({ preventScroll: true })
}
function filterHistory(value) {
  historyType.value = value
  page.value = 1
  load()
}
watch(locale, load)
watch(() => currentUser.value?.id, () => { account.value = null; page.value = 1; load() })
onMounted(load)
</script>

<template>
  <AdminPage class="credits-page">
    <div class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" />
      <main class="admin-dashboard__main credits-page__main">
        <header class="credits-heading"><p class="eyebrow">Wave</p><h1>{{ t('credits.title') }}</h1><p v-if="!account?.unlimited">{{ t('credits.intro') }}</p></header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <LocalizedLink v-if="!currentUser && !loading" class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">{{ t('auth.signIn') }}</LocalizedLink>
        <LoadingSkeleton v-if="loading && !account" variant="dashboard" :label="t('adminDashboard.loading')" />
        <template v-if="account">
          <section class="credit-card credits-overview" :class="{ 'credits-overview--free': account.unlimited }" :aria-label="t('credits.balance')">
            <div class="credits-overview__balance">
              <span class="credits-overview__icon"><Coins :size="27" aria-hidden="true" /></span>
              <div>
                <p class="eyebrow">{{ t('credits.balance') }}</p>
                <h2>{{ account.unlimited ? t('credits.unlimited') : account.balance }} <span v-if="!account.unlimited">{{ t('credits.unit') }}</span></h2>
                <button v-if="!account.unlimited" class="credits-history-link" type="button" @click="showHistory">{{ t('credits.viewHistory') }}<ArrowRight :size="16" aria-hidden="true" /></button>
              </div>
            </div>
            <div v-if="!account.unlimited" class="credits-overview__cost">
              <span class="credits-overview__icon"><Send :size="20" aria-hidden="true" /></span>
              <div><strong>{{ account.settings.applicationCost }}</strong><p>{{ t('credits.applicationUsage') }}</p></div>
            </div>
            <div v-if="!account.unlimited" class="credits-overview__cost">
              <span class="credits-overview__icon"><Plus :size="23" aria-hidden="true" /></span>
              <div><strong>{{ account.settings.campaignCost }}</strong><p>{{ t('credits.campaignUsage') }}</p></div>
            </div>
            <p v-if="account.unlimited" class="credits-overview__note">{{ t('credits.freeHint') }} <span v-if="account.balance">{{ t('credits.savedBalance', { count: account.balance }) }}</span></p>
          </section>

          <div v-if="!account.unlimited" class="credits-purchase-layout">
            <section class="credit-card credits-purchase">
              <header><div><h2>{{ t('credits.buy') }}</h2><p>{{ t('credits.choosePack', { price: money(account.settings.unitPriceMinor) }) }}</p></div></header>
              <fieldset class="credits-packs">
                <legend class="sr-only">{{ t('credits.packs') }}</legend>
                <label v-for="pack in account.settings.packs" :key="pack.amount" class="credits-pack" :class="{ 'is-selected': selectedAmount === pack.amount }">
                  <input v-model="selectedAmount" type="radio" name="credit-pack" :value="pack.amount" />
                  <span><strong>{{ pack.amount }} {{ t('credits.unit') }}</strong><span class="credits-pack__price">{{ money(pack.priceMinor) }}</span><small>{{ t('credits.perCredit', { price: money(account.settings.unitPriceMinor) }) }}</small></span>
                </label>
              </fieldset>
              <div class="credits-checkout">
                <button type="button" class="button button--outline" disabled aria-describedby="credits-payment-notice">{{ t('credits.paymentSoon') }}</button>
                <p id="credits-payment-notice">{{ t('credits.paymentUnavailable') }}</p>
                <p v-if="selectedPack" class="credits-checkout__selection" aria-live="polite">{{ t('credits.selectedPack', { count: selectedPack.amount, price: money(selectedPack.priceMinor) }) }}</p>
              </div>
            </section>
            <section class="credit-card credits-code-card">
              <header><Gift :size="23" aria-hidden="true" /><div><h2>{{ t('credits.haveCode') }}</h2><p>{{ t('credits.codeIntro') }}</p></div></header>
              <form v-form-validation class="credits-redeem" @submit.prevent="redeem">
                <label class="form-field"><span class="sr-only">{{ t('credits.redeemLabel') }}</span><input v-model="code" class="credits-code-input" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required minlength="8" maxlength="8" pattern="[A-Za-z0-9]{8}" :placeholder="t('credits.codePlaceholder')" :disabled="busy" /></label>
                <button class="button button--dark" type="submit" :disabled="busy">{{ t('credits.redeem') }}</button>
              </form>
              <StatusMessage v-if="redeemError" variant="error">{{ redeemError }}</StatusMessage>
              <details class="credits-code-help"><summary><Info :size="15" aria-hidden="true" />{{ t('credits.codeHelp') }}</summary><p>{{ t('credits.purchaseHint') }} {{ t('credits.codeHelpBody') }}</p></details>
            </section>
          </div>

          <section v-if="!account.unlimited" ref="historySection" class="credit-card credits-history" tabindex="-1" :aria-label="t('credits.history')">
            <header>
              <div><h2>{{ t('credits.history') }}</h2><p>{{ t('credits.historyIntro') }}</p></div>
              <SingleSelect :model-value="historyType" :options="historyOptions" :label="t('credits.historyType')" :show-label="false" @update:model-value="filterHistory" />
            </header>
            <div v-if="account.history.length" class="credits-table-wrap" :aria-busy="loading">
              <table class="credits-table">
                <thead><tr><th>{{ t('credits.date') }}</th><th>{{ t('credits.historyType') }}</th><th>{{ t('credits.description') }}</th><th>{{ t('credits.change') }}</th><th>{{ t('credits.balanceAfter') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(entry, index) in account.history" :key="`${page}-${index}`">
                    <td><time :datetime="entry.createdAt">{{ formatDate(entry.createdAt) }}</time></td>
                    <td>{{ entry.amount > 0 ? t('credits.addedType') : t('credits.spentType') }}</td>
                    <td>{{ t(`credits.kinds.${entry.kind}`) }}<small>{{ entry.kind === 'welcome' ? t('credits.activationNumber', { number: entry.reference }) : entry.reference }}</small></td>
                    <td :class="entry.amount > 0 ? 'credits-positive' : 'credits-negative'">{{ entry.amount > 0 ? '+' : '' }}{{ entry.amount }}</td><td>{{ entry.balanceAfter }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <StatusMessage v-else variant="empty">{{ historyType === 'all' ? t('credits.emptyHistory') : t('credits.emptyFilteredHistory') }}</StatusMessage>
            <DirectoryPagination :page="page" :page-size="10" :total="account.total" :show-page-size="false" show-single-page @update:page="page = $event; load()" />
          </section>
        </template>
      </main>
    </div>
    <SuccessModal class="credits-success-modal" v-model:open="success" :title="t('credits.congratulations')" :message="t('credits.added', { count: added })" :close-label="t('credits.close')" show-close>
      <template #icon><Coins :size="30" aria-hidden="true" /></template>
      <template #actions><LocalizedLink class="button button--dark" :to="nextRoute" @click="success = false">{{ company ? t('credits.startPublishing') : t('credits.startApplying') }}<ArrowRight :size="18" aria-hidden="true" /></LocalizedLink><button type="button" class="button button--outline" @click="success = false">{{ t('credits.close') }}</button></template>
    </SuccessModal>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/CreditsView.scss"></style>
