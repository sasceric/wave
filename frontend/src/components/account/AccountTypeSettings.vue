<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRightLeft, Building2, ChevronRight } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, apiRequest, formatDate } from '../../lib/api'
import { currentUser, setCurrentUser } from '../../composables/useCurrentUser'

const { t } = useI18n()
const user = currentUser
const source = computed(() => user.value?.accountType === 'creator' ? 'creator' : user.value?.profile?.companyType || 'brand')
const requests = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const requestOpen = ref(false)
const businessOpen = ref(false)
const targetType = ref('brand')
const profileName = ref('')
const message = ref('')
const details = ref({ registrationId: '', vatId: '', ownerFullName: '' })
const pending = computed(() => requests.value.find(item => item.status === 'pending'))
const latest = computed(() => requests.value[0])
const targetOptions = computed(() => (source.value === 'creator' ? ['brand', 'agency'] : ['creator']).map(value => ({ value, label: t(`accountType.${value}`) })))
let generation = 0
watch(() => [user.value?.id, source.value], load, { immediate: true })
onBeforeUnmount(() => { ++generation })
async function load() {
  const ticket = ++generation
  requests.value = []
  if (!user.value) return
  loading.value = true
  error.value = ''
  try {
    const response = await apiGet('/me/account-type-requests')
    if (ticket === generation) requests.value = response.data
  } catch (cause) { if (ticket === generation) error.value = cause.message }
  finally { if (ticket === generation) loading.value = false }
}
function openRequest() {
  targetType.value = targetOptions.value[0].value
  profileName.value = user.value?.profile?.displayName || user.value?.profile?.name || ''
  message.value = ''
  requestOpen.value = true
}
async function submit() {
  if (busy.value) return
  busy.value = true
  try {
    const { data } = await apiRequest('/me/account-type-requests', { method: 'POST', body: { targetType: targetType.value, profileName: profileName.value, message: message.value }, feedback: { success: t('accountType.sent') } })
    requests.value = [data, ...requests.value]
    requestOpen.value = false
  } catch { /* Shared action toast keeps server errors visible above this dialog. */ }
  finally { busy.value = false }
}
async function withdraw() {
  if (!pending.value || busy.value) return
  busy.value = true
  try {
    const { data } = await apiRequest(`/me/account-type-requests/${pending.value.id}`, { method: 'DELETE' })
    requests.value = requests.value.map(row => row.id === data.id ? data : row)
  } catch { /* Shared action feedback. */ }
  finally { busy.value = false }
}
function openBusiness() {
  const values = user.value?.profile?.businessDetails || {}
  details.value = { registrationId: values.registrationId || '', vatId: values.vatId || '', ownerFullName: values.ownerFullName || '' }
  businessOpen.value = true
}
async function saveBusiness() {
  if (busy.value) return
  const id = user.value?.id
  busy.value = true
  try {
    const { data } = await apiRequest('/me/company-details', { method: 'PUT', body: { ...details.value } })
    if (user.value?.id === id) setCurrentUser(data)
    businessOpen.value = false
  } catch { /* Shared action feedback. */ }
  finally { busy.value = false }
}
</script>

<template>
  <section class="account-settings__group" aria-labelledby="settings-account-type">
    <h2 id="settings-account-type">{{ t('accountType.title') }}</h2>
    <div class="account-settings__card">
      <button class="account-settings__row" type="button" :disabled="loading || Boolean(pending) || !user?.emailVerified" @click="openRequest"><span class="account-settings__icon"><ArrowRightLeft :size="18" aria-hidden="true" /></span><span class="account-settings__text">{{ t('accountType.request') }}<small>{{ t('accountType.current', { type: t(`accountType.${source}`) }) }}</small></span><ChevronRight :size="18" aria-hidden="true" /></button>
      <button v-if="source !== 'creator'" class="account-settings__row" type="button" @click="openBusiness"><span class="account-settings__icon"><Building2 :size="18" aria-hidden="true" /></span><span class="account-settings__text">{{ t('accountType.businessDetails') }}<small>{{ t('accountType.privateHint') }}</small></span><ChevronRight :size="18" aria-hidden="true" /></button>
      <div v-if="latest" class="account-type-settings__status" role="status"><strong>{{ t(`accountType.${latest.status}`) }}</strong><span>{{ t(`accountType.${latest.sourceType}`) }} <span aria-hidden="true">/</span> {{ t(`accountType.${latest.targetType}`) }} · {{ formatDate(latest.createdAt) }}</span><p v-if="latest.response">{{ latest.response }}</p><button v-if="pending" class="button button--outline button--small" type="button" :disabled="busy" @click="withdraw">{{ t('accountType.withdraw') }}</button></div>
    </div>
    <p class="account-type-settings__hint">{{ t('accountType.hint') }}</p>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <FormModal v-if="requestOpen" :open="requestOpen" :title="t('accountType.request')" :description="t('accountType.mergeHint')" :busy="busy" @update:open="requestOpen = $event" @submit="submit">
      <SingleSelect v-model="targetType" :label="t('accountType.target')" :options="targetOptions" :disabled="busy" required />
      <label><span>{{ t('accountType.profileName') }}</span><input v-model.trim="profileName" required minlength="2" maxlength="120" :disabled="busy" /></label>
      <label><span>{{ t('accountType.message') }}</span><textarea v-model.trim="message" maxlength="1000" rows="3" :disabled="busy" /></label>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('listingAds.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('accountType.send') }}</button></template>
    </FormModal>
    <FormModal v-if="businessOpen" :open="businessOpen" :title="t('accountType.businessDetails')" :description="t('accountType.privateHint')" :busy="busy" @update:open="businessOpen = $event" @submit="saveBusiness">
      <label><span>{{ t('accountType.registrationId') }}</span><input v-model.trim="details.registrationId" maxlength="60" :disabled="busy" /></label>
      <label><span>{{ t('accountType.vatId') }}</span><input v-model.trim="details.vatId" maxlength="60" :disabled="busy" /></label>
      <label><span>{{ t('accountType.ownerFullName') }}</span><input v-model.trim="details.ownerFullName" maxlength="120" autocomplete="name" :disabled="busy" /></label>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('listingAds.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('accountType.save') }}</button></template>
    </FormModal>
  </section>
</template>

<style lang="scss" src="../../scss/components/account/AccountTypeSettings.scss"></style>
