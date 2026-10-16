<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bell, ArrowRight } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import SwitchField from '../shared/SwitchField.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { useMarketplaceCatalog } from '../../composables/useMarketplaceCatalog'
import { apiGet, apiRequest } from '../../lib/api'
import countries from '../../data/countries.json'
const { t, locale } = useI18n()
const { categories, error: catalogError } = useMarketplaceCatalog(locale)
const open = ref(false), busy = ref(false), loading = ref(false), error = ref(''), saved = ref(false)
const stored = ref({ enabled: false, categories: [], countries: [], frequency: 'weekly' })
const loaded = ref(false)
const form = ref({ enabled: false, categories: [], countries: [], frequency: 'weekly' })
let revision = 0
const countryOptions = computed(() => {
  const language = locale.value === 'cnr' ? 'bs' : locale.value === 'sr' ? 'sr-Latn' : locale.value
  const labels = new Intl.DisplayNames([language], { type: 'region' })
  return countries.map(value => ({ value, label: labels.of(value) })).sort((a, b) => a.label.localeCompare(b.label, language))
})
const frequencies = computed(() => ['daily', 'weekly'].map(value => ({ value, label: t(`activation.${value}`) })))
async function load() {
  const generation = ++revision, id = currentUser.value?.id
  loaded.value = false
  stored.value = { enabled: false, categories: [], countries: [], frequency: 'weekly' }
  form.value = { ...stored.value }; error.value = ''; saved.value = false
  if (!id) return
  loading.value = true
  try {
    const response = await apiGet('/me/campaign-alerts')
    if (generation !== revision || id !== currentUser.value?.id) return
    if (response.userId !== id) throw new Error(t('api.invalidResponse'))
    stored.value = response.data; form.value = JSON.parse(JSON.stringify(response.data)); loaded.value = true
  } catch (cause) { if (generation === revision) error.value = cause.message }
  finally { if (generation === revision) loading.value = false }
}
async function save() {
  if (busy.value || loading.value || !loaded.value) return
  const generation = revision, id = currentUser.value?.id
  busy.value = true; error.value = ''; saved.value = false
  try {
    const response = await apiRequest('/me/campaign-alerts', { method: 'PUT', body: form.value })
    if (generation !== revision || id !== currentUser.value?.id) return
    if (response.userId !== id) throw new Error(t('api.invalidResponse'))
    stored.value = response.data; form.value = JSON.parse(JSON.stringify(response.data)); saved.value = true; open.value = false
  } catch (cause) { if (generation === revision) error.value = cause.message }
  finally { busy.value = false }
}
watch(() => currentUser.value?.id, load, { immediate: true })
onBeforeUnmount(() => { ++revision })
</script>
<template>
  <section class="activation-alerts">
    <Bell :size="23" aria-hidden="true" /><div><h2>{{ t('activation.alertsTitle') }}</h2><p>{{ t(stored.enabled ? 'activation.alertsEnabled' : 'activation.alertsHint') }}</p><StatusMessage v-if="saved" variant="success">{{ t('activation.saved') }}</StatusMessage></div>
    <button class="button button--outline" type="button" :disabled="loading || !loaded" @click="form = JSON.parse(JSON.stringify(stored)); open = true; saved = false">{{ t('activation.alertsConfigure') }}<ArrowRight :size="15" aria-hidden="true" /></button>
    <StatusMessage v-if="error && !open" variant="error">{{ error }} <button class="text-link" type="button" @click="load">{{ t('reviews.retry') }}</button></StatusMessage>
    <FormModal v-model:open="open" :title="t('activation.alertsTitle')" :description="t('activation.alertsDescription')" :busy="busy" @submit="save">
      <SwitchField :model-value="form.enabled ? 1 : 0" :label="t('activation.alertsSwitch')" :disabled="busy || loading" @update:model-value="form.enabled = $event === 1" />
      <MultiSelect v-model="form.categories" :options="categories" :label="t('auth.category')" :placeholder="t('activation.allCategories')" :search-placeholder="t('account.searchCategories')" :no-results-label="t('account.noCategoriesFound')" :remove-label="t('account.remove')" :max-selections="5" :disabled="busy" />
      <MultiSelect v-model="form.countries" :options="countryOptions" :label="t('auth.country')" :placeholder="t('activation.allCountries')" :search-placeholder="t('auth.searchCountry')" :no-results-label="t('auth.noCountriesFound')" :remove-label="t('account.remove')" :max-selections="10" :disabled="busy" />
      <SingleSelect v-model="form.frequency" :options="frequencies" :label="t('activation.frequency')" :disabled="busy" />
      <p class="activation-alerts__note">{{ t('activation.alertsNote') }}</p>
      <StatusMessage v-if="error || catalogError" variant="error">{{ error || catalogError }}</StatusMessage>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('account.richTextCancel') }}</button><button type="submit" class="button button--dark" :disabled="busy || loading">{{ t('activation.save') }}</button></template>
    </FormModal>
  </section>
</template>
