<script setup>
import { reactive, ref, watch } from 'vue'
import { ChevronRight, LockKeyhole } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'

import { apiRequest } from '../../lib/api'

const props = defineProps({ email: { type: String, required: true }, row: { type: Boolean, default: false } })
const { t } = useI18n()
const recoverySent = ref(false)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref(false)
const fields = reactive({ currentPassword: '', newPassword: '', confirmPassword: '' })
watch(open, value => {
  if (!value) Object.assign(fields, { currentPassword: '', newPassword: '', confirmPassword: '' })
})
function show() {
  Object.assign(fields, { currentPassword: '', newPassword: '', confirmPassword: '' })
  error.value = ''
  recoverySent.value = false
  success.value = false
  open.value = true
}
async function recover() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await apiRequest('/auth/password-reset-requests', { method: 'POST', body: { email: props.email } })
    recoverySent.value = true
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
async function save() {
  if (busy.value) return
  error.value = ''
  if (fields.newPassword !== fields.confirmPassword) {
    error.value = t('passwordChange.mismatch')
    return
  }
  busy.value = true
  try {
    await apiRequest('/me/password', { method: 'PUT', body: { ...fields } })
    Object.assign(fields, { currentPassword: '', newPassword: '', confirmPassword: '' })
    success.value = true
    open.value = false
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="account-password-action" :class="{ 'account-password-action--row': row }">
    <button type="button" @click="show"><LockKeyhole :size="16" aria-hidden="true" /><span>{{ t('passwordChange.title') }}</span><ChevronRight v-if="row" class="account-setting-arrow" :size="16" aria-hidden="true" /></button>
    <span v-if="success" role="status">{{ t('passwordChange.success') }}</span>
  </div>
  <FormModal v-model:open="open" :title="t('passwordChange.title')" :description="t('passwordChange.hint')" :busy="busy" @submit="save">
    <label><span>{{ t('passwordChange.current') }}</span><input v-model="fields.currentPassword" type="password" autocomplete="current-password" required maxlength="4096" /></label>
    <label><span>{{ t('passwordChange.new') }}</span><input v-model="fields.newPassword" type="password" autocomplete="new-password" required minlength="8" maxlength="4096" /></label>
    <label><span>{{ t('passwordChange.confirm') }}</span><input v-model="fields.confirmPassword" type="password" autocomplete="new-password" required minlength="8" maxlength="4096" /></label>
    <p class="password-change-recovery">{{ t('passwordChange.socialHint') }} <button type="button" :disabled="busy" @click="recover">{{ t('passwordChange.recovery') }}</button></p>
    <StatusMessage v-if="recoverySent">{{ t('auth.resetRequested') }}</StatusMessage>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('adminDashboard.cancelAction') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('passwordChange.save') }}</button></template>
  </FormModal>
</template>

<style scoped lang="scss">
.account-password-action { display: grid; gap: 5px; margin-top: 10px; font-size: 12px;
  button { display: inline-flex; align-items: center; gap: 7px; justify-self: start; padding: 5px 0; border: 0; background: transparent; color: var(--forest); font: inherit; cursor: pointer; }
  span { color: var(--forest); }
}
.password-change-recovery {
  margin: 0; font-size: 12px; color: var(--muted); line-height: 1.6;
  button { padding: 0; border: 0; background: transparent; color: var(--forest); font: inherit; text-decoration: underline; cursor: pointer; }
}
</style>
