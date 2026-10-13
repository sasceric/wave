<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronRight, Link } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest, formatDate } from '../../lib/api'
import { getSeoOrigin } from '../../lib/seo'
import { localizedPath } from '../../routePaths'
import { currentUser } from '../../composables/useCurrentUser'
const props = defineProps({ profile: { type: Object, required: true }, kind: { type: String, required: true }, row: { type: Boolean, default: false } })
const emit = defineEmits(['changed'])
const { t } = useI18n()
const open = ref(false)
const busy = ref(false)
const error = ref('')
const slug = ref('')
const saved = ref(null)
const now = ref(Date.now())
let timer
let revision = 0
const routeName = computed(() => ({ creator: 'creator-profile', company: 'company-profile', campaign: 'campaign-detail' }[props.kind]))
const path = computed(() => saved.value?.path || localizedPath(routeName.value, 'bs', { slug: props.profile.slug }))
const url = computed(() => saved.value?.url || new URL(path.value, getSeoOrigin()).href)
const prefix = computed(() => path.value.slice(0, path.value.lastIndexOf('/') + 1))
const available = computed(() => saved.value?.availableAt || props.profile.publicUrlChangeAvailableAt)
const nextChangeLabel = computed(() => available.value ? `${formatDate(available.value)} ${new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(available.value))}` : '')
const locked = computed(() => available.value && new Date(available.value).getTime() > now.value)
const endpoint = computed(() => props.kind === 'campaign' ? `/me/campaigns/${props.profile.id}/public-url` : '/me/public-url')
watch(() => [currentUser.value?.id, props.profile.slug, props.kind, props.profile.id], () => { ++revision; open.value = false; saved.value = null; error.value = ''; busy.value = false })
function show() {
  now.value = Date.now()
  slug.value = saved.value?.slug || props.profile.slug
  error.value = ''
  open.value = true
  clearInterval(timer)
  timer = setInterval(() => { now.value = Date.now() }, 60000)
}
watch(open, value => { if (!value) clearInterval(timer) })
async function save() {
  if (busy.value || locked.value) return
  const generation = ++revision
  const owner = currentUser.value?.id
  busy.value = true; error.value = ''
  try {
    const response = await apiRequest(endpoint.value, { method: 'PUT', body: { slug: slug.value.trim() } })
    if (generation !== revision || currentUser.value?.id !== owner) return
    if (response.userId !== owner) { open.value = false; return }
    saved.value = response.data
    open.value = false
    emit('changed', response.data)
  } catch (cause) {
    if (generation !== revision) return
    error.value = cause.message
    if (cause.status === 409 && cause.availableAt) saved.value = { ...saved.value, availableAt: cause.availableAt }
  } finally { if (generation === revision) busy.value = false }
}
onBeforeUnmount(() => { ++revision; clearInterval(timer) })
</script>
<template>
  <div class="public-url-action" :class="{ 'public-url-action--row': row }">
    <button type="button" @click="show"><Link :size="16" aria-hidden="true" /><span>{{ t('publicUrl.title') }}</span><ChevronRight v-if="row" class="account-setting-arrow" :size="16" aria-hidden="true" /></button>
    <a :href="url" target="_blank" rel="noopener">{{ url }}</a>
  </div>
  <FormModal v-model:open="open" :title="t('publicUrl.title')" :description="t('publicUrl.hint')" :busy="busy" @submit="save">
    <label class="public-url-action__field"><span>{{ t('publicUrl.label') }}</span><span class="public-url-action__input"><span>{{ prefix }}</span><input v-model="slug" type="text" required minlength="2" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocapitalize="none" spellcheck="false" :disabled="locked || busy" /></span></label>
    <p class="public-url-action__preview">{{ getSeoOrigin() }}{{ prefix }}{{ slug }}</p>
    <p class="public-url-action__hint">{{ t('publicUrl.redirectHint') }}</p>
    <StatusMessage v-if="locked">{{ t('publicUrl.locked', { date: nextChangeLabel }) }}</StatusMessage>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('adminDashboard.cancelAction') }}</button><button type="submit" class="button button--dark" :disabled="busy || locked">{{ t('publicUrl.save') }}</button></template>
  </FormModal>
</template>
<style scoped lang="scss">
.public-url-action { display: grid; gap: 5px; margin-top: 8px; font-size: 12px; min-width: 0;
  > button { display: inline-flex; align-items: center; gap: 7px; justify-self: start; padding: 5px 0; border: 0; background: transparent; color: var(--forest); font: inherit; cursor: pointer; }
  > a, &__preview { overflow-wrap: anywhere; font-size: 11px; color: #627971; }
  &__input { display: flex; align-items: center; border: 1px solid #d8dfd8; border-radius: 6px; overflow: hidden; > span { padding: 10px; background: #e8e2da; font-size: 12px; } input { flex: 1; min-width: 0; border: 0; border-radius: 0; } }
  &__hint { color: #627971; font-size: 12px; line-height: 1.7; }
}
</style>
