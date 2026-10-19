<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CheckCheck, ClipboardCheck, PencilLine } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import ReviewAction from '../reviews/ReviewAction.vue'
import { apiRequest, formatDate } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'

const props = defineProps({ offer: { type: Object, required: true }, provider: { type: Boolean, required: true }, inquiry: { type: Boolean, default: false }, disabled: { type: Boolean, default: false }, campaignFinished: { type: Boolean, default: false }, campaignId: { type: Number, default: null }, canFinishCampaign: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])
const { t } = useI18n()
const action = ref('')
const reason = ref('')
const busy = ref(false)
const error = ref('')
const reviewOpen = ref(false)
const completed = computed(() => Boolean(props.offer.workCompletedAt) || props.campaignFinished)
const status = computed(() => completed.value ? 'completed' : props.offer.workStatus || 'in_progress')
function open(next) { action.value = next; reason.value = ''; error.value = '' }
async function submit() {
  if (busy.value || props.disabled || !action.value) return
  if (action.value === 'changes' && (reason.value.trim().length < 10 || reason.value.trim().length > 2000)) return
  const id = props.offer.id
  const inquiry = props.inquiry
  const userId = currentUser.value?.id
  const choice = action.value
  busy.value = true
  error.value = ''
  try {
    const response = choice === 'finish'
      ? await apiRequest(`/company/campaigns/${props.campaignId}/status`, { method: 'PUT', body: { status: 'finished' } })
      : await apiRequest(`/me/${inquiry ? 'inquiry-offers' : 'offers'}/${id}/work`, { method: 'POST', body: { action: choice, ...(choice === 'changes' ? { reason: reason.value.trim() } : {}) } })
    if (props.offer.id !== id || props.inquiry !== inquiry || currentUser.value?.id !== userId) return
    action.value = ''
    emit('changed', response.data)
    if (choice === 'complete' && props.offer.applicationId) reviewOpen.value = true
  } catch (cause) { if (props.offer.id === id && currentUser.value?.id === userId) error.value = cause.message }
  finally { busy.value = false }
}
watch([() => props.offer.id, () => props.inquiry, () => currentUser.value?.id], () => { action.value = ''; reason.value = ''; error.value = ''; reviewOpen.value = false })
</script>

<template>
  <section class="campaign-work-action" :aria-label="t('work.label')">
    <p class="campaign-work-action__status">{{ t(`work.${status}`) }}<span v-if="offer.workCompletedAt"> · {{ formatDate(offer.workCompletedAt) }}</span></p>
    <p v-if="offer.workChangesReason" class="campaign-work-action__reason">{{ offer.workChangesReason }}</p>
    <template v-if="!completed && !disabled">
      <button v-if="provider && status !== 'submitted'" type="button" class="button button--outline" @click="open('submit')"><ClipboardCheck :size="16" aria-hidden="true" />{{ t('work.submit') }}</button>
      <template v-else-if="!provider && status === 'submitted'">
        <button type="button" class="button button--dark" @click="open('complete')"><CheckCheck :size="16" aria-hidden="true" />{{ t('work.complete') }}</button>
        <button v-if="status === 'submitted'" type="button" class="button button--outline" @click="open('changes')"><PencilLine :size="16" aria-hidden="true" />{{ t('work.changes') }}</button>
      </template>
    </template>
    <ReviewAction v-if="completed && offer.applicationId && !disabled" :application-id="offer.applicationId" :auto-open="reviewOpen" @closed="reviewOpen = false" />
    <button v-if="!provider && canFinishCampaign && campaignId && !disabled" type="button" class="button button--outline" @click="open('finish')"><CheckCheck :size="16" aria-hidden="true" />{{ t('reviews.finishCampaign') }}</button>
    <FormModal :open="Boolean(action)" :title="action === 'finish' ? t('reviews.finishCampaign') : action ? t(`work.${action}`) : t('work.label')" :description="action === 'finish' ? t('reviews.finishHint') : inquiry && action === 'complete' ? t('work.directCompleteHint') : action ? t(`work.${action}Hint`) : ''" :busy="busy" @update:open="!$event && (action = '')" @submit="submit">
      <label v-if="action === 'changes'" class="form-field"><span>{{ t('work.reason') }}</span><textarea v-model.trim="reason" required minlength="10" maxlength="2000" :placeholder="t('work.reasonHint')" /></label>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('hiring.confirm') }}</button></template>
    </FormModal>
  </section>
</template>

<style scoped lang="scss">
.campaign-work-action {
  display: grid;
  gap: 10px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid #eee9e1;
  &__status, &__reason { margin: 0; font-size: 12px; line-height: 1.6; }
  &__status { color: var(--forest); font-weight: 600; }
  &__reason { color: var(--muted); white-space: pre-wrap; overflow-wrap: anywhere; }
}
</style>
