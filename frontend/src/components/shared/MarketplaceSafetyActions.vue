<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Flag, Ban } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import reasons from '../../../../config/report_reasons.json'
import { currentUser } from '../../composables/useCurrentUser'
import { apiRequest, apiUpload } from '../../lib/api'
import { localizedRouteName } from '../../routePaths'
import FormModal from './FormModal.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import SingleSelect from './SingleSelect.vue'
import StatusMessage from './StatusMessage.vue'
const props = defineProps({ kind: { type: String, required: true }, slug: { type: String, required: true }, ownerSlug: { type: String, default: '' } })
const { t, locale } = useI18n()
const router = useRouter()
const reporting = ref(false)
const blocking = ref(false)
const busy = ref(false)
const error = ref('')
const category = ref('')
const reason = ref('')
const description = ref('')
const files = ref([])
let uploaded = []
let submitted = false
async function discardUploads() { const ids = uploaded; uploaded = []; if (!submitted) await Promise.allSettled(ids.map(id => apiRequest(`/media/${id}`, { method: 'DELETE' }))) }
function selectFiles(event) {
  const selected = [...event.target.files]
  if (selected.length > 3 || selected.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10485760)) { error.value = t('safety.evidenceHint'); event.target.value = ''; return }
  files.value = selected
}
watch(reporting, value => { if (!value && !busy.value) { discardUploads(); files.value = [] } })
onBeforeUnmount(discardUploads)
const own = computed(() => currentUser.value?.profile?.slug === (props.ownerSlug || props.slug) && currentUser.value?.accountType === (props.kind === 'creator' ? 'creator' : 'company'))
const available = computed(() => currentUser.value?.approved && currentUser.value?.emailVerified && !own.value)
const categories = computed(() => Object.keys(reasons).map(value => ({ value, label: t(`safety.categories.${value}`) })))
const choices = computed(() => (reasons[category.value] || []).map(value => ({ value, label: t(`safety.reasons.${value}`) })))
watch(category, () => { reason.value = category.value === 'other' ? 'other' : '' })
watch(() => currentUser.value?.id, () => { reporting.value = false; blocking.value = false; error.value = ''; description.value = '' })
function report() { submitted = false; files.value = []; category.value = ''; reason.value = ''; description.value = ''; error.value = ''; reporting.value = true }
function notice(title, message) { window.dispatchEvent(new CustomEvent('wave:safety-notice', { detail: { userId: currentUser.value?.id, title, message } })) }
async function submit() {
  if (busy.value || !available.value || !category.value || !reason.value) return
  busy.value = true; error.value = ''
  try {
    const userId = currentUser.value.id
    for (let index = uploaded.length; index < files.value.length; index += 1) {
      if (currentUser.value?.id !== userId) return
      const result = await apiUpload('/media?folder=report-evidence', files.value[index]); uploaded.push(result.data.id)
    }
    if (currentUser.value?.id !== userId) return
    await apiRequest(`/me/safety/${props.kind}/${encodeURIComponent(props.slug)}/report`, { method: 'POST', body: { category: category.value, reason: reason.value, description: description.value, attachmentIds: uploaded } })
    submitted = true
    if (currentUser.value?.id !== userId) return
    reporting.value = false
    window.dispatchEvent(new CustomEvent('wave:report-update'))
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
async function block() {
  if (busy.value || !available.value) return
  busy.value = true; error.value = ''
  try {
    const userId = currentUser.value.id
    await apiRequest(`/me/safety/${props.kind}/${encodeURIComponent(props.slug)}/block`, { method: 'POST', body: {} })
    if (currentUser.value?.id !== userId) return
    blocking.value = false
    window.dispatchEvent(new CustomEvent('wave:safety-changed'))
    await router.push({ name: localizedRouteName(props.kind === 'creator' ? 'creators' : props.kind === 'company' ? 'companies' : 'campaigns', locale.value) })
    notice(t('safety.blocked'), t('safety.blockedHint'))
  } catch (cause) { error.value = cause.message; blocking.value = false }
  finally { busy.value = false }
}
</script>
<template>
  <div v-if="available" class="marketplace-safety-actions">
    <button type="button" @click="report"><Flag :size="14" aria-hidden="true" />{{ t('safety.report') }}</button>
    <button type="button" @click="blocking = true; error = ''"><Ban :size="14" aria-hidden="true" />{{ t(props.kind === 'creator' ? 'safety.blockCreator' : 'safety.blockCompany') }}</button>
    <StatusMessage v-if="error && !reporting" variant="error">{{ error }}</StatusMessage>
    <FormModal v-model:open="reporting" :title="t('safety.report')" :description="t('safety.reportHint')" :busy="busy" @submit="submit">
      <SingleSelect v-model="category" :label="t('safety.category')" :options="categories" required :disabled="busy" />
      <SingleSelect v-if="category" v-model="reason" :label="t('safety.reason')" :options="choices" required :disabled="busy" />
      <label><span>{{ t('safety.description') }}</span><textarea v-model="description" :required="category === 'other'" :minlength="category === 'other' ? 10 : undefined" maxlength="2000" :disabled="busy" :placeholder="t('safety.descriptionHint')" /></label>
      <label><span>{{ t('safety.evidence') }}</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple :disabled="busy || uploaded.length > 0" @change="selectFiles" /><small>{{ t('safety.evidenceHint') }}</small></label>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <template #actions="{ close }"><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button class="button button--dark" type="submit" :disabled="busy || !reason">{{ t('safety.send') }}</button></template>
    </FormModal>
    <ConfirmationModal v-model:open="blocking" :title="t(props.kind === 'creator' ? 'safety.blockCreator' : 'safety.blockCompany')" :message="t('safety.blockHint')" :confirm-label="t('safety.block')" :cancel-label="t('safety.cancel')" :loading="busy" @confirm="block" />
  </div>
</template>
<style scoped lang="scss">
.marketplace-safety-actions { display: flex; flex-wrap: wrap; gap: 16px; margin: 18px 0; > button { display: inline-flex; align-items: center; gap: 6px; background: transparent; border: 0; padding: 5px 0; font: inherit; font-size: 12px; color: var(--muted); cursor: pointer; &:hover { color: var(--forest); } } }
</style>
