<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { UsersRound, ArrowRight, LayoutGrid, List } from '@lucide/vue'
import CardGrid from '../components/shared/CardGrid.vue'
import CreatorCard from '../components/creators/CreatorCard.vue'
import DirectoryToolbar from '../components/shared/DirectoryToolbar.vue'
import DirectorySearch from '../components/shared/DirectorySearch.vue'
import DirectoryFilterPanel from '../components/shared/DirectoryFilterPanel.vue'
import DirectoryHero from '../components/shared/DirectoryHero.vue'
import DirectoryHeroCollage from '../components/shared/DirectoryHeroCollage.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import CountryDirectoryLinks from '../components/shared/CountryDirectoryLinks.vue'
import SeoGuideLinks from '../components/shared/SeoGuideLinks.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { apiGet } from '../lib/api'
import { SOCIAL_PLATFORMS } from '../lib/marketplace'
import { creatorTypeOptions } from '../lib/creatorTypes'
import { updateSeo, getSeoOrigin } from '../lib/seo'
import { localizedPath } from '../routePaths'
import { countryCodeForSlug } from '../lib/regionalSeo'

const props = defineProps({ countryCode: { type: String, default: '' } })

const { t, locale } = useI18n()
const route = useRoute()
const search = ref(queryString(route.query.q))
const selectedCategories = ref([])
const selectedCreatorTypes = ref([])
const typeOptions = computed(() => creatorTypeOptions(t))
const creatorTypes = computed(() => encodeSelection(selectedCreatorTypes.value))
const selectedPlatforms = ref(normalizePlatform(route.query.platform))
const selectedCountries = ref([])
const categories = computed(() => encodeSelection(selectedCategories.value))
const platforms = computed(() => encodeSelection(selectedPlatforms.value))
const countries = computed(() => encodeSelection(props.countryCode ? [props.countryCode] : selectedCountries.value))
const city = ref('')
const audience = ref('')
const sort = ref('newest')
const layout = ref('grid')
const filtersOpen = ref(false)
const facets = ref({ countries: [] })
const facetsLoaded = ref(false)
const facetError = ref('')
let facetVersion = 0
let disposed = false
const catalog = useMarketplaceCatalog(locale)
const { items: creators, total, loading, error, hasMore, loadMore } = useInfiniteDirectory('/creators', locale, { q: search, categories, creatorTypes, platforms, countries, city, audience, sort }, props.countryCode ? 24 : 30)
const platformOptions = SOCIAL_PLATFORMS.map((value) => ({ value, label: value }))
const countryOptions = computed(() => {
  const names = new Intl.DisplayNames([locale.value === 'cnr' ? 'bs' : locale.value === 'sr' ? 'sr-Latn' : locale.value], { type: 'region' })
  return facets.value.countries.map((option) => ({ ...option, label: ['BA', 'HR', 'RS', 'SI', 'ME', 'MK'].includes(option.value) ? t(`companyDirectory.countryNames.${option.value}`) : names.of(option.value) || option.value }))
})
const audienceOptions = computed(() => [
  { value: '', label: t('creatorDirectory.allAudiences') },
  { value: 'small', label: t('creatorDirectory.audienceSmall') },
  { value: 'medium', label: t('creatorDirectory.audienceMedium') },
  { value: 'large', label: t('creatorDirectory.audienceLarge') },
])
const sortOptions = computed(() => [
  { value: 'newest', label: t('creatorDirectory.sortNewest') },
  { value: 'followers', label: t('creatorDirectory.sortFollowers') },
  { value: 'name', label: t('companyDirectory.sortName') },
])
const activeFilterCount = computed(() => selectedCategories.value.length + selectedCreatorTypes.value.length + selectedPlatforms.value.length + selectedCountries.value.length + [city.value, audience.value].filter(Boolean).length)
const imageSizes = '(max-width: 420px) calc((100vw - 44px) / 2), (max-width: 760px) calc((min(100vw - 40px, 560px) - 12px) / 2), (max-width: 1224px) calc((100vw - 336px) / 4), 210px'
watch(() => route.query.q, (value) => { search.value = queryString(value) })
watch(() => route.query.platform, (value) => { selectedPlatforms.value = normalizePlatform(value) })
watch(locale, loadFacets)
watch([locale, facets, facetsLoaded, facetError, creators], () => {
  if (!props.countryCode || route.meta.routeName !== 'country-creators' || countryCodeForSlug(route.params.country) !== props.countryCode || (!facetsLoaded.value && !facetError.value)) return
  updateSeo({
    route,
    locale: locale.value,
    title: `${t(`regionalSeo.countries.${props.countryCode}.title`)} | Wave`,
    description: t(`regionalSeo.countries.${props.countryCode}.description`),
    noindex: Boolean(facetError.value) || !facets.value.countries.some(country => country.value === props.countryCode && country.count > 0),
    mainEntity: { '@type': 'ItemList', itemListElement: creators.value.map((creator, index) => ({ '@type': 'ListItem', position: index + 1, name: creator.displayName, url: new URL(localizedPath('creator-profile', locale.value, { slug: creator.slug }), getSeoOrigin()).href })) },
  })
}, { flush: 'post' })
onMounted(loadFacets)
onBeforeUnmount(() => { disposed = true; facetVersion += 1 })
function queryString(value) { return typeof value === 'string' ? value : '' }
function encodeSelection(values) { return values.length ? JSON.stringify(values) : '' }
function normalizePlatform(value) {
  const platform = typeof value === 'string' ? SOCIAL_PLATFORMS.find((item) => item.toLowerCase() === value.toLowerCase()) : null
  return platform ? [platform] : []
}
async function loadFacets() {
  const version = ++facetVersion
  facetError.value = ''
  try {
    const response = await apiGet('/creators/filters', { locale: locale.value })
    if (!disposed && version === facetVersion) {
      facets.value = response.data
      facetsLoaded.value = true
    }
  } catch (cause) {
    if (!disposed && version === facetVersion) facetError.value = cause.message
  }
}
function clearFilters() {
  selectedCategories.value = []
  selectedCreatorTypes.value = []
  selectedPlatforms.value = []
  selectedCountries.value = []
  city.value = ''
  audience.value = ''
}
</script>

<template>
  <section class="page-width creator-directory">
    <DirectoryHero class="creator-directory__hero">
      <p class="eyebrow">{{ t(countryCode ? 'regionalSeo.eyebrow' : 'creatorsPage.eyebrow') }}</p>
      <h1 v-if="countryCode">{{ t(`regionalSeo.countries.${countryCode}.title`) }}</h1>
      <h1 v-else>{{ t('creatorsPage.headlineLead') }} <em v-if="t('creatorsPage.headlineEmphasis')">{{ t('creatorsPage.headlineEmphasis') }}</em></h1>
      <p>{{ countryCode ? t(`regionalSeo.countries.${countryCode}.intro`) : t('creatorsPage.description') }}</p>
      <template #art>
        <DirectoryHeroCollage main-image="https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&amp;fit=crop&amp;w=640&amp;q=85" secondary-image="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&amp;fit=crop&amp;w=240&amp;q=85" detail-image="/images/companies-hero.webp">
          <UsersRound :size="26" /><span>{{ t('creatorDirectory.heroBadge') }}</span><ArrowRight :size="17" />
        </DirectoryHeroCollage>
      </template>
    </DirectoryHero>

    <DirectoryToolbar v-model:search="search" v-model:filters-open="filtersOpen" :search-label="t('creatorsPage.searchLabel')" :search-placeholder="t('creatorsPage.search')" :filters-label="t('companyDirectory.filterButton')" filters-id="creator-filters" :active-filter-count="activeFilterCount" :control-columns="2">
      <DirectorySearch v-model="city" :icon="false" :placeholder="t('companyDirectory.locationPlaceholder')" :label="t('companyDirectory.cityLabel')" />
      <SingleSelect v-model="sort" :options="sortOptions" :label="t('creatorDirectory.sortLabel')" :show-label="false" />
    </DirectoryToolbar>

    <div class="creator-directory__content">
      <div class="creator-directory__sidebar">
        <DirectoryFilterPanel id="creator-filters" v-model="filtersOpen" :title="t('companyDirectory.filterTitle')" :clear-label="t('companyDirectory.clearFilters')" :apply-label="t('companyDirectory.applyFilters', { count: total })" @clear="clearFilters">
          <StatusMessage v-if="facetError || catalog.error.value" variant="error">{{ facetError || catalog.error.value }} <button type="button" @click="loadFacets(); catalog.refresh()">{{ t('companyDirectory.retryFilters') }}</button></StatusMessage>
          <fieldset>
            <MultiSelect v-model="selectedCreatorTypes" :options="typeOptions" :label="t('creatorTypes.label')" :placeholder="t('creatorTypes.all')" :search-placeholder="t('creatorTypes.search')" :no-results-label="t('creatorTypes.empty')" :remove-label="t('account.remove')" />
          </fieldset>
          <fieldset>
            <MultiSelect v-model="selectedCategories" :options="catalog.categories.value" :label="t('creatorDirectory.categoryLabel')" :placeholder="t('categories.all')" :search-placeholder="t('creatorDirectory.searchCategories')" :no-results-label="t('creatorDirectory.noCategories')" :remove-label="t('account.remove')" />
          </fieldset>
          <fieldset v-if="!countryCode">
            <MultiSelect v-model="selectedCountries" :options="countryOptions" :label="t('companyDirectory.countryLabel')" :placeholder="t('companyDirectory.countryLabel')" :search-placeholder="t('companyDirectory.countryPlaceholder')" :no-results-label="t('auth.noCountriesFound')" :remove-label="t('account.remove')" />
          </fieldset>
          <fieldset>
            <MultiSelect v-model="selectedPlatforms" :options="platformOptions" :label="t('creatorDirectory.platformLabel')" :placeholder="t('platforms.all')" :search-placeholder="t('creatorDirectory.searchPlatforms')" :no-results-label="t('creatorDirectory.noPlatforms')" :remove-label="t('account.remove')" />
          </fieldset>
          <fieldset>
            <SingleSelect v-model="audience" :options="audienceOptions" :label="t('creatorDirectory.audienceLabel')" />
            <p class="creator-directory__hint">{{ t('creatorDirectory.audienceHint') }}</p>
          </fieldset>
          <template #mobile><fieldset><SingleSelect v-model="sort" :options="sortOptions" :label="t('creatorDirectory.sortLabel')" /></fieldset></template>
        </DirectoryFilterPanel>
        <div class="creator-directory__sidebar-links">
          <SeoGuideLinks />
          <CountryDirectoryLinks :available-countries="facets.countries" />
        </div>
      </div>
      <div class="creator-directory__results">
        <div class="creator-directory__results-head">
          <p aria-live="polite">{{ t('creatorsPage.creatorCount', { count: total }) }}</p>
          <div class="creator-directory__view-options" :aria-label="t('creatorDirectory.layoutLabel')">
            <button type="button" :aria-pressed="layout === 'grid'" @click="layout = 'grid'"><LayoutGrid :size="16" aria-hidden="true" />{{ t('companyDirectory.grid') }}</button>
            <button type="button" :aria-pressed="layout === 'list'" @click="layout = 'list'"><List :size="16" aria-hidden="true" />{{ t('companyDirectory.list') }}</button>
          </div>
        </div>
        <StatusMessage v-if="!loading && !error && !creators.length" variant="empty">{{ t(countryCode && !search && activeFilterCount === 0 ? 'regionalSeo.empty' : 'creatorsPage.empty') }}</StatusMessage>
        <CardGrid v-if="creators.length || loading" kind="creator" layout="directory" :class="{ 'creator-directory__grid--list': layout === 'list' }" :aria-busy="loading">
          <CreatorCard v-for="creator in creators" :key="creator.id" :creator="creator" :image-sizes="layout === 'list' ? '(max-width: 760px) 50vw, 360px' : imageSizes" />
          <DirectorySkeletonCard v-for="index in loading ? (creators.length ? 2 : 8) : 0" :key="`loading-${index}`" kind="creator" />
        </CardGrid>
        <DirectoryLoadMore :loading="loading" :error="error" :has-more="hasMore" :count="creators.length" @load="loadMore" />
      </div>
    </div>
    <section v-if="countryCode" class="seo-directory-copy">
      <h2>{{ t('regionalSeo.selectTitle') }}</h2>
      <p>{{ t('regionalSeo.selectBody') }}</p>
      <h2>{{ t('regionalSeo.processTitle') }}</h2>
      <p>{{ t('regionalSeo.processBody') }}</p>
    </section>
  </section>
</template>

<style lang="scss" src="../scss/views/CreatorsView.scss"></style>
