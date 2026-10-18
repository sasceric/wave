<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RotateCcw } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'

const props = defineProps({ conversationId: { type: Number, required: true }, reopening: { type: Object, required: true }, provider: { type: Boolean, required: true }, canRequest: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])
const { t } = useI18n()
const open = ref(false)
const message = ref('')
const busy = ref(false)
const error = ref('')
function request() { message.value = t('discussion.defaultMessage'); error.value = ''; open.value = true }
async function submit(action) {
  if (busy.value) return
  if (action === 'request' && (message.value.trim().length < 10 || message.value.trim().length > 1000)) return
  const id = props.conversationId
  const version = props.reopening.version
  const userId = currentUser.value?.id
  busy.value = true
  error.value = ''
  try {
    const response = await apiRequest(`/me/conversations/${id}/reopen`, { method: 'POST', body: { action, version, ...(action === 'request' ? { message: message.value.trim() } : {}) } })
    if (props.conversationId !== id || currentUser.value?.id !== userId) return
    open.value = false
    emit('changed', response.data)
  } catch (cause) { if (props.conversationId === id && currentUser.value?.id === userId) error.value = cause.message }
  finally { busy.value = false }
}
watch([() => props.conversationId, () => currentUser.value?.id], () => { open.value = false; message.value = ''; error.value = '' })
</script>

<template>
  <section class="discussion-reopening" :aria-label="t('discussion.title')">
    <button v-if="canRequest && !provider" type="button" class="button button--outline" :disabled="busy" @click="request"><RotateCcw :size="16" aria-hidden="true" />{{ t('discussion.request') }}</button>
    <template v-if="reopening.status === 'pending'">
      <p>{{ t(provider ? 'discussion.invitation' : 'discussion.pending') }}</p>
      <p v-if="provider && reopening.message">{{ reopening.message }}</p>
      <template v-if="provider">
        <button type="button" class="button button--dark" :disabled="busy" @click="submit('accept')">{{ t('discussion.accept') }}</button>
        <button type="button" class="button button--outline" :disabled="busy" @click="submit('reject')">{{ t('discussion.reject') }}</button>
        <small>{{ t('discussion.hint') }}</small>
      </template>
    </template>
    <StatusMessage v-if="error && !open" variant="error">{{ error }}</StatusMessage>
    <FormModal v-model:open="open" :title="t('discussion.request')" :description="t('discussion.hint')" :busy="busy" @submit="submit('request')">
      <label class="form-field"><span>{{ t('discussion.message') }}</span><textarea v-model.trim="message" required minlength="10" maxlength="1000" /></label>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('discussion.send') }}</button></template>
    </FormModal>
  </section>
</template>

<style scoped lang="scss">
.discussion-reopening {
  display: grid;
  gap: 10px;
  margin-top: 16px;
  p, small { margin: 0; font-size: 12px; line-height: 1.6; overflow-wrap: anywhere; white-space: pre-wrap; }
  > .button { width: 100%; min-height: 40px; padding: 10px 12px; font-size: 12px; justify-content: center; }
}
</style>
