<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Ban } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
const props = defineProps({ offerId: { type: Number, required: true }, autoOpen: { type: Boolean, default: false }, hideTrigger: { type: Boolean, default: false } })
const emit = defineEmits(['cancelled', 'closed'])
const { t } = useI18n()
const open = ref(props.autoOpen)
const busy = ref(false)
const error = ref('')
async function cancel() {
  if (busy.value) return
  const id = currentUser.value?.id
  const offerId = props.offerId
  busy.value = true
  error.value = ''
  try {
    const response = await apiRequest(`/me/offers/${offerId}/cancel`, { method: 'POST' })
    if (currentUser.value?.id !== id || props.offerId !== offerId) return
    open.value = false
    emit('cancelled', response.data)
  } catch (cause) { if (currentUser.value?.id === id) error.value = cause.message }
  finally { busy.value = false }
}
watch(open, value => { if (!value) emit('closed') })
watch(() => [currentUser.value?.id, props.offerId], () => { open.value = false; error.value = '' })
</script>
<template>
  <button v-if="!hideTrigger" class="campaign-cancel-action" type="button" @click="open = true"><Ban :size="14" aria-hidden="true" />{{ t('hiring.cancelCollaboration') }}</button>
  <FormModal v-model:open="open" :title="t('hiring.cancelCollaboration')" :description="t('hiring.cancelCollaborationHint')" :busy="busy" @submit="cancel">
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }"><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button class="button button--dark" type="submit" :disabled="busy">{{ t('hiring.confirmCancellation') }}</button></template>
  </FormModal>
</template>
<style scoped lang="scss">
.campaign-cancel-action { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; padding: 0 10px; border: 1px solid #dfe4dc; border-radius: 6px; color: #6f7d74; background: transparent; font: inherit; font-size: 11px; cursor: pointer; &:hover { background: #f1f3ef; color: var(--forest); } &:focus-visible { outline: 2px solid var(--forest); outline-offset: 2px; } }
</style>
