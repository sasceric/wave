<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { currentUser } from '../../composables/useCurrentUser'
import { apiGet, apiRequest } from '../../lib/api'
import FormModal from './FormModal.vue'
import StatusMessage from './StatusMessage.vue'
const { t, locale } = useI18n()
const notices = ref([])
const acknowledgement = ref(null)
const busy = ref(false)
const error = ref('')
let revision = 0
const active = computed(() => acknowledgement.value || notices.value[0])
async function load() {
  const user = currentUser.value
  const current = ++revision
  if (!user?.approved || !user.emailVerified) { notices.value = []; return }
  try {
    const result = await apiGet('/me/safety/notices')
    if (current !== revision || currentUser.value?.id !== user.id || result.userId !== user.id) return
    notices.value = result.data
  } catch { /* Durable notices are retried on the next session, focus or event. */ }
}
async function close() {
  if (busy.value) return
  if (acknowledgement.value) { acknowledgement.value = null; return }
  const notice = notices.value[0]
  if (!notice) return
  const userId = currentUser.value?.id
  busy.value = true; error.value = ''
  try {
    await apiRequest(`/me/safety/notices/${notice.id}/read`, { method: 'POST', body: {} })
    if (currentUser.value?.id === userId) { notices.value = notices.value.filter(row => row.id !== notice.id); if (!notices.value.length) load() }
  } catch (cause) { if (currentUser.value?.id === userId) error.value = cause.message }
  finally { busy.value = false }
}
function announce(event) { if (event.detail?.userId === currentUser.value?.id) acknowledgement.value = event.detail }
function focus() { if (document.visibilityState === 'visible') load() }
watch(() => [currentUser.value?.id, currentUser.value?.approved, currentUser.value?.emailVerified], () => { ++revision; notices.value = []; acknowledgement.value = null; error.value = ''; busy.value = false; load() }, { immediate: true })
watch(locale, load)
onMounted(() => { window.addEventListener('wave:report-update', load); window.addEventListener('wave:safety-notice', announce); window.addEventListener('focus', focus) })
onBeforeUnmount(() => { ++revision; window.removeEventListener('wave:report-update', load); window.removeEventListener('wave:safety-notice', announce); window.removeEventListener('focus', focus) })
</script>
<template>
  <FormModal :open="Boolean(active)" :title="active?.title || t('safety.reportUpdate')" :busy="busy" @update:open="!$event && close()" @submit="close">
    <template v-if="active"><p v-if="active.status"><strong>{{ t(`safety.statuses.${active.status}`) }}</strong> · #{{ active.reportId?.slice(0, 8).toUpperCase() }}</p><p class="report-notice-message">{{ active.message || t(active.status === 'received' ? 'safety.reportSentHint' : 'safety.processingHint') }}</p></template>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions><button class="button button--dark" type="submit" :disabled="busy">{{ t('safety.close') }}</button></template>
  </FormModal>
</template>
<style scoped>.report-notice-message { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.7; font-size: 13px; }</style>
