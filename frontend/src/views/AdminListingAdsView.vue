<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, Pencil, Plus, ArrowUpRight, Trash2 } from '@lucide/vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import FormModal from '../components/shared/FormModal.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import ImageUploadControl from '../components/shared/ImageUploadControl.vue'
import ListingAdCard from '../components/shared/ListingAdCard.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, apiUpload } from '../lib/api'
import { AD_KINDS, AD_PALETTES, emptyListingAd, listingEntries } from '../lib/listingAds'

const { t, locale } = useI18n()
const kind = ref('creators')
const rows = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const open = ref(false)
const draft = ref(emptyListingAd())
const upload = ref(null)
const savedDraft = ref('')
let request
const tabs = computed(() => AD_KINDS.map(key => ({ key, label: t(`listingAds.${key}`) })))
const visibleCount = computed(() => rows.value.filter(row => row.enabled).length)
const spanOptions = computed(() => [1, 2].map(value => ({ value, label: t(`listingAds.span${value}`) })))
const previewEntries = computed(() => listingEntries(Array.from({ length: 12 }, (_, i) => ({ id: i + 1 })), [{ ...draft.value, id: 'preview' }]).slice(0, 16))
const dirty = computed(() => open.value && JSON.stringify(draft.value) !== savedDraft.value)
watch(() => [kind.value, locale.value, currentUser.value?.isAdmin], load, { immediate: true })
onBeforeUnmount(() => request?.abort())
onBeforeRouteLeave(() => !dirty.value || window.confirm(t('listingAds.leave')))

async function load() {
  request?.abort()
  rows.value = []
  if (!currentUser.value?.isAdmin) return
  const controller = new AbortController()
  request = controller
  loading.value = true
  error.value = ''
  try {
    const response = await apiGet(`/admin/listing-ads/${kind.value}`, { signal: controller.signal })
    if (!controller.signal.aborted) rows.value = response.data
  } catch (cause) {
    if (!controller.signal.aborted) error.value = cause.message
  } finally { if (!controller.signal.aborted) loading.value = false }
}
function edit(row) {
  draft.value = row ? JSON.parse(JSON.stringify(row)) : emptyListingAd(kind.value)
  savedDraft.value = JSON.stringify(draft.value)
  open.value = true
}
function close() {
  if (!busy.value && (!dirty.value || window.confirm(t('listingAds.leave')))) open.value = false
}
async function persist(row, editor = false) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  const body = JSON.parse(JSON.stringify(row))
  try {
    const response = await apiRequest(`/admin/listing-ads/${body.kind}${body.id ? `/${body.id}` : ''}`, { method: body.id ? 'PUT' : 'POST', body, feedback: { success: t('listingAds.saved') } })
    if (body.kind === kind.value) {
      const index = rows.value.findIndex(item => item.id === response.data.id)
      if (index < 0) rows.value.push(response.data)
      else rows.value[index] = response.data
    }
    if (editor) open.value = false
  } catch {
    // Keep the draft open and saved rows unchanged; the API shows the error toast.
  } finally { busy.value = false }
}
async function imageSelected(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file || busy.value) return
  busy.value = true
  try {
    const { data } = await apiUpload('/media?folder=listing-ad', file)
    Object.assign(draft.value, { imageId: data.id, imageUrl: data.url, image: data.image, artwork: '' })
  } catch { /* The shared API displays upload failures. */ }
  finally { busy.value = false }
}
function resize(value) {
  draft.value.span = value
  for (const place of draft.value.placements) place.column = Math.min(place.column, 5 - value)
}
function addPlacement() {
  draft.value.placements.push({ row: Math.min(100, Math.max(...draft.value.placements.map(place => place.row)) + 2), column: 1 })
}
</script>

<template>
  <AdminPage>
    <div v-if="currentUser?.isAdmin" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" admin-layout />
      <main class="admin-dashboard__main listing-ads-admin">
        <header class="admin-dashboard__heading"><h1>{{ t('listingAds.title') }}</h1><p>{{ t('listingAds.intro') }}</p></header>
        <div class="listing-ads-admin__toolbar">
          <ProfileTabs v-model="kind" :items="tabs" :label="t('listingAds.directory')" id-prefix="listing-ads" />
          <button class="button button--dark" type="button" :disabled="busy || loading" @click="edit()"><Plus :size="16" aria-hidden="true" />{{ t('listingAds.create') }}</button>
        </div>
        <section :key="kind" role="tabpanel" :aria-labelledby="`listing-ads-tab-${kind}`">
          <div class="listing-ads-admin__summary"><span>{{ t('listingAds.activeCount', { count: visibleCount }) }}</span><LocalizedLink :to="{ name: kind }" target="_blank" rel="noopener" class="text-link">{{ t('listingAds.openDirectory') }}<ArrowUpRight :size="16" aria-hidden="true" /></LocalizedLink></div>
          <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
          <StatusMessage v-if="loading" variant="info">{{ t('adminDashboard.loading') }}</StatusMessage>
          <StatusMessage v-else-if="!rows.length" variant="empty">{{ t('listingAds.empty') }}</StatusMessage>
          <div v-else class="listing-ads-admin__cards">
            <article v-for="row in rows" :key="row.id" class="listing-ads-admin__card">
              <div class="listing-ads-admin__creative"><ListingAdCard :ad="row" preview /></div>
              <div class="listing-ads-admin__details"><div class="listing-ads-admin__identity"><h2>{{ row.name }}</h2><span :class="{ 'is-active': row.enabled }">{{ t(row.enabled ? 'listingAds.active' : 'listingAds.inactive') }}</span></div><p>{{ t(`listingAds.span${row.span}`) }}</p><div class="listing-ads-admin__positions"><span v-for="(place, i) in row.placements" :key="i">{{ t('listingAds.position', place) }}</span></div><footer><button class="button button--outline button--small" type="button" :disabled="busy" @click="edit(row)"><Pencil :size="14" aria-hidden="true" />{{ t('listingAds.edit') }}</button><SwitchField :model-value="row.enabled ? 1 : 0" :label="t('listingAds.active') + ': ' + row.name" :disabled="busy" @update:model-value="persist({ ...row, enabled: $event === 1 })" /></footer></div>
            </article>
          </div>
          <p class="listing-ads-admin__hint">{{ t('listingAds.positionsHint') }}</p>
        </section>
      </main>
      <FormModal v-if="open" :open="open" :title="t(draft.id ? 'listingAds.edit' : 'listingAds.create')" :busy="busy" wide @update:open="!$event && close()" @submit="persist(draft, true)">
        <fieldset class="listing-ads-admin__fieldset" :disabled="busy">
          <div class="listing-ads-admin__editor">
            <div class="listing-ads-admin__fields">
              <label><span>{{ t('listingAds.name') }}</span><input v-model.trim="draft.name" required maxlength="100" /></label>
              <label><span>{{ t('listingAds.headline') }}</span><input v-model.trim="draft.title" required maxlength="100" /></label>
              <label><span>{{ t('listingAds.body') }}</span><textarea v-model.trim="draft.body" rows="3" maxlength="300" /></label>
              <div class="listing-ads-admin__pair"><label><span>{{ t('listingAds.button') }}</span><input v-model.trim="draft.buttonLabel" maxlength="40" :required="Boolean(draft.url)" /></label><label><span>{{ t('listingAds.url') }}</span><input v-model.trim="draft.url" maxlength="1000" :required="Boolean(draft.buttonLabel)" placeholder="https://" /></label></div>
              <div class="listing-ads-admin__pair"><label><span>{{ t('listingAds.helpLabel') }}</span><input v-model.trim="draft.helpLabel" maxlength="80" :required="Boolean(draft.helpUrl)" /></label><label><span>{{ t('listingAds.helpUrl') }}</span><input v-model.trim="draft.helpUrl" maxlength="1000" :required="Boolean(draft.helpLabel)" placeholder="/podrska" /></label></div>
              <SingleSelect :model-value="draft.span" :label="t('listingAds.size')" :options="spanOptions" :disabled="busy" @update:model-value="resize" />
              <fieldset class="listing-ads-admin__palette"><legend>{{ t('listingAds.color') }}</legend><label v-for="color in AD_PALETTES" :key="color" :class="`listing-ads-admin__swatch--${color}`"><input v-model="draft.palette" type="radio" name="ad-palette" :value="color" /><span class="listing-ads-admin__swatch"><Check v-if="draft.palette === color" :size="18" aria-hidden="true" /></span><span>{{ t(`listingAds.${color}`) }}</span></label></fieldset>
              <ImageUploadControl folder="listing-ad" :preview-url="draft.imageUrl" :add-label="t('listingAds.addImage')" :change-label="t('listingAds.changeImage')" :remove-label="t('listingAds.removeImage')" :helper-text="t('listingAds.imageHint')" :can-remove="Boolean(draft.imageUrl)" :disabled="busy" @choose="upload?.click()" @remove="Object.assign(draft, { imageId: null, imageUrl: '', image: null, artwork: '' })" />
              <button v-if="!draft.imageUrl" class="button button--tonal button--small" type="button" :disabled="busy" @click="Object.assign(draft, { artwork: 'campaign-invitation', imageId: null, image: null, imageUrl: '/images/listing-ads/create-campaign.svg' })">{{ t('listingAds.useArtwork') }}</button>
              <input ref="upload" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" tabindex="-1" :aria-label="t('listingAds.addImage')" @change="imageSelected" />
              <div class="listing-ads-admin__placement-head"><h3>{{ t('listingAds.placements') }}</h3><button type="button" class="button button--tonal button--small" :disabled="draft.placements.length >= 12 || busy" @click="addPlacement"><Plus :size="16" aria-hidden="true" />{{ t('listingAds.addPlacement') }}</button></div>
              <div v-for="(place, index) in draft.placements" :key="index" class="listing-ads-admin__placement"><label><span>{{ t('listingAds.row') }}</span><input v-model.number="place.row" type="number" min="1" max="100" required /></label><SingleSelect v-model="place.column" :label="t('listingAds.column')" :options="Array.from({ length: 5 - draft.span }, (_, i) => ({ value: i + 1, label: String(i + 1) }))" :disabled="busy" /><button class="button button--outline listing-ads-admin__remove" type="button" :disabled="busy || draft.placements.length === 1" :aria-label="t('listingAds.removePlacement')" @click="draft.placements.splice(index, 1)"><Trash2 :size="21" aria-hidden="true" /></button></div>
              <p class="listing-ads-admin__hint">{{ t('listingAds.positionsHint') }}</p>
              <SwitchField :model-value="draft.enabled ? 1 : 0" :label="t('listingAds.active')" :description="t('listingAds.activeHint')" :disabled="busy" @update:model-value="draft.enabled = $event === 1" />
            </div>
            <aside class="listing-ads-admin__preview"><span>{{ t('listingAds.preview') }}</span><ListingAdCard :ad="draft" preview /><h3>{{ t('listingAds.desktopLayout') }}</h3><div class="listing-ads-admin__map" aria-hidden="true"><div v-for="entry in previewEntries" :key="entry.key" :class="{ 'is-ad': entry.ad }" :style="entry.ad ? { gridColumn: `span ${entry.ad.span}` } : undefined">{{ entry.ad ? t('listingAds.adLabel') : entry.item.id }}</div></div><p>{{ t('listingAds.previewHint') }}</p></aside>
          </div>
        </fieldset>
        <template #actions><button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('listingAds.cancel') }}</button><button class="button button--dark" type="submit" :disabled="busy"><Check :size="16" aria-hidden="true" />{{ t(busy ? 'listingAds.saving' : 'listingAds.save') }}</button></template>
      </FormModal>
    </div>
    <main v-else class="admin-dashboard__main"><LocalizedLink :to="{ name: 'account' }" class="button button--dark">{{ t('auth.signIn') }}</LocalizedLink></main>
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminListingAdsView.scss"></style>
