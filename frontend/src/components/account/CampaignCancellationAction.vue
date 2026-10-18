<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Ban } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
const props = defineProps({ offerId: { type: Number, required: true }, autoOpen: { type: Boolean, default: false }, hideTrigger: { type: Boolean, default: false }, fullWidth: { type: Boolean, default: false }, inquiry: { type: Boolean, default: false } })
const emit = defineEmits(['cancelled', 'closed'])
const { t } = useI18n()
const open = ref(props.autoOpen)
const busy = ref(false)
const error = ref('')
const reason = ref('')
async function cancel() {
  if (busy.value) return
  if (reason.value.trim().length < 10 || reason.value.trim().length > 1000) {
    error.value = t('hiring.cancellationReasonHint')
    return
  }
  const id = currentUser.value?.id
  const offerId = props.offerId
  const inquiry = props.inquiry
  busy.value = true
  error.value = ''
  try {
    const response = await apiRequest(`/me/${inquiry ? 'inquiry-offers' : 'offers'}/${offerId}/cancel`, { method: 'POST', body: { reason: reason.value.trim() } })
    if (currentUser.value?.id !== id || props.offerId !== offerId || props.inquiry !== inquiry) return
    open.value = false
    emit('cancelled', response.data)
  } catch (cause) { if (currentUser.value?.id === id) error.value = cause.message }
  finally { busy.value = false }
}
watch(open, value => { if (!value) emit('closed') })
watch(() => [currentUser.value?.id, props.offerId, props.inquiry], () => { open.value = false; error.value = ''; reason.value = '' })
</script>
<template>
  <button v-if="!hideTrigger" class="campaign-cancel-action" :class="{ 'campaign-cancel-action--full-width': fullWidth }" type="button" @click="open = true"><Ban :size="14" aria-hidden="true" />{{ t('hiring.cancelCollaboration') }}</button>
  <FormModal v-model:open="open" :title="t('hiring.cancelCollaboration')" :description="t('hiring.cancelCollaborationHint')" :busy="busy" @submit="cancel">
    <label class="form-field"><span>{{ t('hiring.cancellationReason') }}</span><textarea v-model.trim="reason" required minlength="10" maxlength="1000" :placeholder="t('hiring.cancellationReasonHint')" /><small>{{ t('hiring.cancellationReasonHint') }}</small></label>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }"><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button class="button button--dark" type="submit" :disabled="busy">{{ t('hiring.confirmCancellation') }}</button></template>
  </FormModal>
</template>
<style scoped lang="scss">
.campaign-cancel-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 32px;
  padding: 0 10px;
  border: 1px solid #dba99e;
  border-radius: 6px;
  color: #a84232;
  background: transparent;
  font: inherit;
  font-size: 11px;
  cursor: pointer;

  &:hover { background: #fbefeb; border-color: #a84232; }
  &:focus-visible { outline: 2px solid #a84232; outline-offset: 2px; }

  &--full-width {
    display: flex;
    box-sizing: border-box;
    width: 100%;
    min-height: 43px;
    margin-top: 12px;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 12px;
  }
}
</style>
