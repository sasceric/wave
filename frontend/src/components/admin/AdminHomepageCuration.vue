<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, ExternalLink, Sparkles, X } from '@lucide/vue'
import ProfileTabs from '../shared/ProfileTabs.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import StatusMessage from '../shared/StatusMessage.vue'

const props = defineProps({
  mode: { type: String, required: true },
  selections: { type: Object, required: true },
  expirations: { type: Object, required: true },
  catalog: { type: Object, required: true },
  candidates: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  dirty: { type: Boolean, default: false },
})
const emit = defineEmits(['mode', 'select', 'expiry', 'search', 'load-more', 'save'])
const { t } = useI18n()
const active = ref('creators')
const tabs = computed(() => ['creators', 'companies', 'campaigns'].map(key => ({ key, label: t(`adminDashboard.${key}`), count: props.selections[key].length })))
const modes = computed(() => [
  { key: 'latest', label: t('adminHome.recommendedMode') },
  { key: 'featured', label: t('adminDashboard.featuredCreators') },
])
const selectedRows = computed(() => props.selections[active.value].map(id => props.catalog[active.value].find(row => row.id === id)).filter(Boolean))
const candidateState = computed(() => props.candidates[active.value])
const title = row => row.displayName || row.name || row.title
const image = row => row.avatarUrl || row.logoUrl || row.coverImageUrl
const subtitle = row => active.value === 'creators' ? [row.category, row.city].filter(Boolean).join(' · ') : active.value === 'companies' ? row.industry : row.company?.name
const option = row => ({ value: row.id, label: title(row), description: subtitle(row), image: image(row) })
const options = computed(() => candidateState.value.rows.map(option))
const selectionOptions = computed(() => props.catalog[active.value].map(option))
function route(row) {
  return { name: active.value === 'creators' ? 'creator-profile' : active.value === 'companies' ? 'company-profile' : 'campaign-detail', params: { slug: row.slug } }
}
</script>

<template>
  <section class="admin-home">
    <p class="admin-home__intro">{{ t('adminHome.intro') }}</p>
    <div class="admin-home__mode">
      <div><h2>{{ t('adminDashboard.creatorMode') }}</h2><p>{{ t('adminHome.modeHint') }}</p></div>
      <ProfileTabs :model-value="mode" :items="modes" :label="t('adminDashboard.creatorMode')" id-prefix="homepage-mode" @update:model-value="emit('mode', $event)" />
    </div>
    <div class="admin-home__toolbar">
      <ProfileTabs v-model="active" :items="tabs" :label="t('adminHome.featuredSelection')" id-prefix="homepage-picks" />
    </div>
    <section class="admin-home__panel" role="tabpanel" :aria-labelledby="`homepage-picks-tab-${active}`">
      <header><Sparkles :size="18" aria-hidden="true" /><div><h2>{{ t('adminHome.featuredSelection') }}</h2><p>{{ t(active === 'campaigns' ? 'adminDashboard.campaignPicksHint' : active === 'companies' ? 'adminDashboard.companyPicksHint' : 'adminHome.rankingHint') }}</p></div></header>
      <div class="admin-home__select">
        <MultiSelect
          :key="active"
          :model-value="selections[active]"
          :options="options"
          :selection-options="selectionOptions"
          :label="t(`adminDashboard.${active}`)"
          :placeholder="t('adminHome.choose')"
          :search-placeholder="t('adminDashboard.searchOptions')"
          :no-results-label="t('adminDashboard.noOptions')"
          :remove-label="t('account.remove')"
          :loading-label="t('adminDashboard.loading')"
          :load-more-label="t(candidateState.error ? 'directoryLoading.retry' : 'directoryLoading.loadMore')"
          :loading="candidateState.loading"
          :has-more="candidateState.error || candidateState.rows.length < candidateState.total"
          :disabled="busy"
          :show-selected-values="false"
          show-images
          remote
          @update:model-value="emit('select', active, $event)"
          @search="emit('search', active, $event)"
          @load-more="emit('load-more', active)"
        />
      </div>
      <div v-if="selectedRows.length" class="admin-home__selections">
        <article v-for="row in selectedRows" :key="row.id" class="admin-home__row">
          <img v-if="image(row)" :src="image(row)" alt="" />
          <span v-else class="admin-home__initial" aria-hidden="true">{{ title(row)?.charAt(0) }}</span>
          <div class="admin-home__identity"><strong>{{ title(row) }}</strong><span>{{ subtitle(row) }}</span><LocalizedLink :to="route(row)" target="_blank" rel="noopener">{{ t('adminHome.viewProfile') }}<ExternalLink :size="12" aria-hidden="true" /></LocalizedLink></div>
          <div v-if="active !== 'campaigns'" class="admin-home__expiry">
            <DatePicker :model-value="expirations[active]?.[row.id] || ''" :label="t('adminHome.until')" :placeholder="t('adminHome.noExpiry')" :disabled="busy" @update:model-value="emit('expiry', active, row.id, $event)" />
            <button v-if="expirations[active]?.[row.id]" class="text-link" type="button" :disabled="busy" @click="emit('expiry', active, row.id, '')">{{ t('adminHome.noExpiry') }}</button>
          </div>
          <button class="admin-home__remove" type="button" :disabled="busy" :aria-label="t('adminDashboard.removeOption', { name: title(row) })" @click="emit('select', active, selections[active].filter(id => id !== row.id))"><X :size="18" aria-hidden="true" /></button>
        </article>
      </div>
      <StatusMessage v-else variant="empty">{{ t('adminHome.noPicks') }}</StatusMessage>
    </section>
    <footer class="admin-home__save">
      <span :class="{ 'is-dirty': dirty }"><Check v-if="!dirty" :size="16" aria-hidden="true" />{{ t(dirty ? 'adminHome.unsaved' : 'adminHome.saved') }}</span>
      <button class="button button--dark" type="button" :disabled="busy || !dirty" @click="emit('save')">{{ t(busy ? 'adminDashboard.saving' : 'adminDashboard.save') }}</button>
    </footer>
  </section>
</template>
<style lang="scss" src="../../scss/components/admin/AdminHomepageCuration.scss"></style>
