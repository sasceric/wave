<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, UserRoundCheck } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import { CURRENCIES } from '../../lib/marketplace'
import { apiRequest } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'

const props = defineProps({ conversationId: { type: Number, default: null }, inquiryId: { type: Number, default: null }, campaign: { type: Object, required: true }, creatorName: { type: String, default: '' }, disabled: { type: Boolean, default: false }, buttonClass: { type: String, default: 'button button--dark' }, showArrow: { type: Boolean, default: false } })
const emit = defineEmits(['offered'])
const { t } = useI18n()
const open = ref(false)
const busy = ref(false)
const amount = ref('')
const dueDate = ref('')
const currency = ref('BAM')
function today() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}
const message = ref('')
const error = ref('')
function start() {
  amount.value = ''
  dueDate.value = ''
  currency.value = props.campaign.currency || 'BAM'
  message.value = t('hiring.defaultMessage')
  error.value = ''
  open.value = true
}
async function submit() {
  if (busy.value || props.disabled || !Number.isInteger(amount.value) || amount.value < 1 || amount.value > 10000000 || !dueDate.value || dueDate.value < today()) return
  const userId = currentUser.value?.id
  const conversationId = props.conversationId
  const inquiryId = props.inquiryId
  busy.value = true
  error.value = ''
  try {
    const response = await apiRequest(inquiryId ? `/company/inquiries/${inquiryId}/hire` : `/company/conversations/${conversationId}/hire`, { method: 'POST', body: { ...(inquiryId ? { currency: currency.value } : {}), amount: amount.value, dueDate: dueDate.value, message: message.value } })
    if (currentUser.value?.id !== userId || props.conversationId !== conversationId || props.inquiryId !== inquiryId) return
    open.value = false
    emit('offered', response.data)
  } catch (cause) {
    if (currentUser.value?.id === userId && props.conversationId === conversationId && props.inquiryId === inquiryId) error.value = cause.message
  } finally { busy.value = false }
}
watch(() => [props.conversationId, props.inquiryId, currentUser.value?.id], () => { open.value = false; error.value = '' })
</script>
<template>
  <button type="button" :class="buttonClass" :disabled="disabled || busy" @click="start"><UserRoundCheck v-if="!showArrow" :size="16" aria-hidden="true" />{{ t('hiring.hire') }}<ArrowRight v-if="showArrow" :size="16" aria-hidden="true" /></button>
  <FormModal v-model:open="open" :title="t('hiring.confirmTitle')" :description="t('hiring.confirmHint')" :busy="busy" @submit="submit">
    <p><strong>{{ creatorName }}</strong> · {{ campaign.title }}</p>
    <label class="form-field"><span>{{ t('account.offerAmount') }} ({{ currency === 'BAM' ? 'KM' : currency }})</span><input v-model.number="amount" type="number" min="1" max="10000000" step="1" required /></label>
    <SingleSelect v-if="inquiryId" v-model="currency" :label="t('account.currency')" :options="CURRENCIES.map(value => ({ value, label: value }))" required />
    <DatePicker v-model="dueDate" :label="t('hiring.dueDate')" :min="today()" required />
    <label class="form-field"><span>{{ t('account.offerMessage') }}</span><textarea v-model.trim="message" minlength="10" maxlength="1500" required /></label>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('hiring.sendOffer') }}</button></template>
  </FormModal>
</template>
