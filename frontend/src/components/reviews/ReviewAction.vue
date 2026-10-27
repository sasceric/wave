<script setup>
import { ref, watch } from 'vue'
import { Pencil, Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, apiRequest, formatDate } from '../../lib/api'
const props = defineProps({ applicationId: { type: Number, required: true }, autoOpen: { type: Boolean, default: false }, hideTrigger: { type: Boolean, default: false }, editOnOpen: { type: Boolean, default: false } })
const emit = defineEmits(['closed', 'saved'])
const { t } = useI18n()
const open = ref(false)
const busy = ref(false)
const error = ref('')
const state = ref(null)
const rating = ref(0)
const comment = ref('')
const editing = ref(false)
function edit() {
  if (!state.value?.canEditOwn || !state.value.own) return
  rating.value = state.value.own.rating
  comment.value = state.value.own.comment
  editing.value = true
  error.value = ''
}
async function show() {
  if (busy.value) return
  open.value = true
  state.value = null
  error.value = ''
  rating.value = 0
  comment.value = ''
  editing.value = false
  busy.value = true
  try {
    state.value = (await apiGet(`/me/applications/${props.applicationId}/review`)).data
    if (props.editOnOpen) edit()
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
async function submit() {
  if (busy.value || !(editing.value ? state.value?.canEditOwn : state.value?.canReview)) return
  error.value = ''
  if (!rating.value) { error.value = t('reviews.chooseRating'); return }
  busy.value = true
  try {
    state.value = (await apiRequest(`/me/applications/${props.applicationId}/review`, { method: editing.value ? 'PATCH' : 'POST', body: { rating: rating.value, comment: comment.value } })).data
    editing.value = false
    comment.value = ''
    emit('saved')
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
watch(() => [props.applicationId, props.autoOpen], () => { if (props.autoOpen) show() }, { immediate: true })
watch(open, (value, previous) => { if (previous && !value) emit('closed') })
</script>
<template>
  <button v-if="!hideTrigger" class="button button--outline" type="button" @click="show"><Star :size="15" aria-hidden="true" />{{ t('reviews.action') }}</button>
  <FormModal v-model:open="open" :title="t(editing ? 'reviews.editOwn' : 'reviews.action')" :description="t(editing ? 'reviews.editHint' : 'reviews.policy')" :busy="busy" @submit="submit">
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <p v-if="busy && !state" role="status">{{ t('reviews.loading') }}</p>
    <template v-if="state">
      <template v-if="state.canReview || editing">
        <fieldset class="review-stars"><legend>{{ t('reviews.score') }}</legend><label v-for="value in 5" :key="value"><input v-model="rating" type="radio" :value="value" name="rating" required /><Star :size="26" :fill="value <= rating ? 'currentColor' : 'none'" aria-hidden="true" /><span class="sr-only">{{ t('reviews.stars', { count: value }) }}</span></label></fieldset>
        <label><span>{{ t('reviews.comment') }}</span><textarea v-model="comment" required minlength="10" maxlength="2000" :placeholder="t('reviews.placeholder')" /></label>
        <p v-if="!editing" class="review-action-hint">{{ t('reviews.deadline', { date: formatDate(state.deadline) }) }}</p>
      </template>
      <StatusMessage v-else-if="!state.own" variant="info">{{ t('reviews.unavailable') }}</StatusMessage>
      <article v-if="state.own && !editing" class="review-action-feedback">
        <strong>{{ t('reviews.yours') }} · {{ state.own.rating }}/5</strong>
        <p>{{ state.own.comment }}</p>
        <small v-if="!state.received">{{ t('reviews.waiting') }}</small>
        <button v-if="state.canEditOwn" class="button button--outline" type="button" :disabled="busy" @click="edit">
          <Pencil :size="15" aria-hidden="true" />{{ t('reviews.editOwn') }}
        </button>
      </article>
      <article v-if="state.received" class="review-action-feedback"><strong>{{ t('reviews.received') }} · {{ state.received.rating }}/5</strong><p>{{ state.received.comment }}</p></article>
    </template>
    <template #actions="{ close }">
      <button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('reviews.close') }}</button>
      <button v-if="state?.canReview || editing" class="button button--dark" type="submit" :disabled="busy">{{ t(editing ? 'reviews.saveEdit' : 'reviews.submit') }}</button>
    </template>
  </FormModal>
</template>
<style scoped lang="scss">
.review-stars { border: 0; padding: 0; margin: 0; display: flex; gap: 6px; legend { font-size: 12px; margin-bottom: 10px; } label { position: relative; padding: 6px; color: var(--forest); cursor: pointer; } input { position: absolute; opacity: 0; } label:has(input:focus-visible) { outline: 2px solid var(--forest); outline-offset: 2px; border-radius: 6px; } }
.review-action-hint { font-size: 12px; color: var(--muted); margin: 0; }
.review-action-feedback { border: 1px solid #dce2d9; border-radius: 10px; padding: 14px; font-size: 13px; p { white-space: pre-wrap; overflow-wrap: anywhere; margin: 8px 0; line-height: 1.6; } small { display: block; color: var(--muted); } .button { margin-top: 12px; } }
</style>
