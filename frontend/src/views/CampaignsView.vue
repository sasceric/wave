<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Megaphone, ArrowRight, LayoutGrid, List } from '@lucide/vue'
import CardGrid from '../components/shared/CardGrid.vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import DirectoryHero from '../components/shared/DirectoryHero.vue'
import DirectoryHeroCollage from '../components/shared/DirectoryHeroCollage.vue'
import DirectoryToolbar from '../components/shared/DirectoryToolbar.vue'
import DirectorySearch from '../components/shared/DirectorySearch.vue'
import DirectoryFilterPanel from '../components/shared/DirectoryFilterPanel.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'

const { t, locale } = useI18n()
const route = useRoute()
const search = ref(queryString(route.query.q))
const selectedCategories = ref([])
const selectedChannels = ref([])
const categories = computed(() => encodeSelection(selectedCategories.value))
const channels = computed(() => encodeSelection(selectedChannels.value))
const location = ref('')
const currency = ref('')
const budgetMin = ref('')
const budgetMax = ref('')
const budgetLower = computed(() => budgetMin.value === '' ? '' : String(budgetMin.value))
const budgetUpper = computed(() => budgetMax.value === '' ? '' : String(budgetMax.value))
const sort = ref('recommended')
const layout = ref('grid')
const filtersOpen = ref(false)
const catalog = useMarketplaceCatalog(locale)
const { items: campaigns, total, loading, error, hasMore, loadMore } = useInfiniteDirectory('/campaigns', locale, { q: search, categories, channels, location, currency, budgetMin: budgetLower, budgetMax: budgetUpper, sort })
const channelOptions = SOCIAL_PLATFORMS.map((value) => ({ value, label: value }))
const currencyOptions = computed(() => [{ value: '', label: t('campaignDirectory.allCurrencies') }, ...CURRENCIES.map((value) => ({ value, label: value }))])
const sortOptions = computed(() => [
  { value: 'recommended', label: t('companyDirectory.sortRecommended') },
  { value: 'newest', label: t('creatorDirectory.sortNewest') },
  { value: 'closing', label: t('campaignDirectory.sortClosing') },
])
const activeFilterCount = computed(() => selectedCategories.value.length + selectedChannels.value.length + [location.value, currency.value, budgetMin.value, budgetMax.value].filter((value) => value !== '').length)
const imageSizes = '(max-width: 420px) calc((100vw - 44px) / 2), (max-width: 760px) calc((min(100vw - 40px, 560px) - 12px) / 2), (max-width: 1224px) calc((100vw - 336px) / 4), 210px'
watch(() => route.query.q, (value) => { search.value = queryString(value) })
watch(currency, (value) => { if (!value) { budgetMin.value = ''; budgetMax.value = '' } }, { flush: 'sync' })
function queryString(value) { return typeof value === 'string' ? value : '' }
function encodeSelection(values) { return values.length ? JSON.stringify(values) : '' }
function clearFilters() {
  selectedCategories.value = []
  selectedChannels.value = []
  location.value = ''
  currency.value = ''
  budgetMin.value = ''
  budgetMax.value = ''
}
</script>

<template>
  <section class="page-width campaign-directory">
    <DirectoryHero class="campaign-directory__hero">
      <p class="eyebrow">{{ t('campaignsPage.eyebrow') }}</p>
      <h1>{{ t('campaignsPage.headlineLead') }} <em>{{ t('campaignsPage.headlineEmphasis') }}</em></h1>
      <p>{{ t('campaignsPage.description') }}</p>
      <template #art>
        <DirectoryHeroCollage main-image="/images/bag.webp" secondary-image="/images/companies-photographer.webp" detail-image="/images/companies-hero.webp">
          <Megaphone :size="26" /><span>{{ t('campaignDirectory.heroBadge') }}</span><ArrowRight :size="17" />
        </DirectoryHeroCollage>
      </template>
    </DirectoryHero>

    <DirectoryToolbar v-model:search="search" v-model:filters-open="filtersOpen" :search-label="t('campaignsPage.searchLabel')" :search-placeholder="t('campaignsPage.search')" :filters-label="t('companyDirectory.filterButton')" filters-id="campaign-filters" :active-filter-count="activeFilterCount" :control-columns="2">
      <DirectorySearch v-model="location" :icon="false" :placeholder="t('campaignDirectory.locationPlaceholder')" :label="t('campaignDirectory.locationLabel')" />
      <SingleSelect v-model="sort" :options="sortOptions" :label="t('campaignDirectory.sortLabel')" :show-label="false" />
    </DirectoryToolbar>

    <div class="campaign-directory__content">
      <DirectoryFilterPanel id="campaign-filters" v-model="filtersOpen" :title="t('companyDirectory.filterTitle')" :clear-label="t('companyDirectory.clearFilters')" :apply-label="t('companyDirectory.applyFilters', { count: total })" @clear="clearFilters">
        <StatusMessage v-if="catalog.error.value" variant="error">{{ catalog.error.value }} <button type="button" @click="catalog.refresh()">{{ t('companyDirectory.retryFilters') }}</button></StatusMessage>
        <fieldset>
          <MultiSelect v-model="selectedCategories" :options="catalog.categories.value" :label="t('creatorDirectory.categoryLabel')" :placeholder="t('categories.all')" :search-placeholder="t('creatorDirectory.searchCategories')" :no-results-label="t('creatorDirectory.noCategories')" :remove-label="t('account.remove')" />
        </fieldset>
        <fieldset>
          <MultiSelect v-model="selectedChannels" :options="channelOptions" :label="t('campaignDirectory.channelLabel')" :placeholder="t('campaignDirectory.allChannels')" :search-placeholder="t('creatorDirectory.searchPlatforms')" :no-results-label="t('creatorDirectory.noPlatforms')" :remove-label="t('account.remove')" />
        </fieldset>
        <fieldset>
          <div class="campaign-directory__field"><span>{{ t('campaignDirectory.locationLabel') }}</span><DirectorySearch v-model="location" :placeholder="t('campaignDirectory.locationPlaceholder')" :label="t('campaignDirectory.locationLabel')" /></div>
        </fieldset>
        <fieldset>
          <SingleSelect v-model="currency" :options="currencyOptions" :label="t('campaignDirectory.currencyLabel')" />
        </fieldset>
        <fieldset>
          <legend>{{ t('campaignDirectory.budgetLabel') }}</legend>
          <div class="campaign-directory__budget">
            <label class="campaign-directory__field"><span>{{ t('campaignDirectory.budgetFrom') }}</span><input v-model="budgetMin" type="number" inputmode="numeric" min="0" max="10000000" step="1" :disabled="!currency" :placeholder="t('campaignDirectory.budgetFrom')" /></label>
            <label class="campaign-directory__field"><span>{{ t('campaignDirectory.budgetTo') }}</span><input v-model="budgetMax" type="number" inputmode="numeric" min="0" max="10000000" step="1" :disabled="!currency" :placeholder="t('campaignDirectory.budgetTo')" /></label>
          </div>
          <p class="campaign-directory__hint">{{ t(currency ? 'campaignDirectory.budgetHint' : 'campaignDirectory.chooseCurrency') }}</p>
        </fieldset>
        <template #mobile><fieldset><SingleSelect v-model="sort" :options="sortOptions" :label="t('campaignDirectory.sortLabel')" /></fieldset></template>
      </DirectoryFilterPanel>
      <div class="campaign-directory__results">
        <div class="campaign-directory__results-head">
          <p aria-live="polite">{{ t('campaignsPage.campaignCount', { count: total }) }}</p>
          <div class="campaign-directory__view-options" :aria-label="t('campaignDirectory.layoutLabel')">
            <button type="button" :aria-pressed="layout === 'grid'" @click="layout = 'grid'"><LayoutGrid :size="16" aria-hidden="true" />{{ t('companyDirectory.grid') }}</button>
            <button type="button" :aria-pressed="layout === 'list'" @click="layout = 'list'"><List :size="16" aria-hidden="true" />{{ t('companyDirectory.list') }}</button>
          </div>
        </div>
        <StatusMessage v-if="!loading && !error && !campaigns.length" variant="empty">{{ t('campaignsPage.empty') }}</StatusMessage>
        <CardGrid v-if="campaigns.length || loading" kind="campaign" layout="directory" :class="{ 'campaign-directory__grid--list': layout === 'list' }" :aria-busy="loading">
          <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="layout === 'list' ? '(max-width: 760px) 50vw, 360px' : imageSizes" />
          <DirectorySkeletonCard v-for="index in loading ? (campaigns.length ? 2 : 8) : 0" :key="`loading-${index}`" kind="campaign" />
        </CardGrid>
        <DirectoryLoadMore :loading="loading" :error="error" :has-more="hasMore" :count="campaigns.length" @load="loadMore" />
      </div>
    </div>
  </section>
</template>

<style lang="scss" src="../scss/views/CampaignsView.scss"></style>
