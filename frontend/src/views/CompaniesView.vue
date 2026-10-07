<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { LayoutGrid, List, Sparkles } from '@lucide/vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import CardGrid from '../components/shared/CardGrid.vue'
import CompanyCard from '../components/companies/CompanyCard.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import DirectoryHero from '../components/shared/DirectoryHero.vue'
import DirectoryToolbar from '../components/shared/DirectoryToolbar.vue'
import DirectorySearch from '../components/shared/DirectorySearch.vue'
import DirectoryFilterPanel from '../components/shared/DirectoryFilterPanel.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'
import { apiGet } from '../lib/api'

const route = useRoute()
const { locale, t } = useI18n()
const search = ref(queryString(route.query.q))
const selectedIndustries = ref([])
const industries = computed(() => selectedIndustries.value.length ? JSON.stringify(selectedIndustries.value) : '')
const city = ref('')
const selectedCountries = ref([])
const countries = computed(() => selectedCountries.value.length ? JSON.stringify(selectedCountries.value) : '')
const verified = ref(false)
const featured = ref(false)
const sort = ref('recommended')
const status = computed({
  get: () => verified.value && featured.value ? 'both' : verified.value ? 'verified' : featured.value ? 'featured' : '',
  set: (value) => { verified.value = ['verified', 'both'].includes(value); featured.value = ['featured', 'both'].includes(value) },
})
const filtersOpen = ref(false)
const layout = ref('grid')
const facets = ref({ industries: [], countries: [] })
const facetError = ref('')
let facetVersion = 0
let disposed = false
const { items: companies, total, loading, error, hasMore, loadMore } = useInfiniteDirectory(
  '/companies', locale, { q: search, industries, city, countries, verified, featured, sort },
)
const countryOptions = computed(() => {
  const names = new Intl.DisplayNames([locale.value === 'cnr' ? 'bs' : locale.value === 'sr' ? 'sr-Latn' : locale.value], { type: 'region' })
  return facets.value.countries.map((item) => {
    const key = `companyDirectory.countryNames.${item.value}`
    return { ...item, label: ['BA', 'HR', 'RS', 'SI', 'ME', 'MK'].includes(item.value) ? t(key) : names.of(item.value) || item.value }
  })
})
const activeFilterCount = computed(() => selectedIndustries.value.length + selectedCountries.value.length + [city.value, verified.value, featured.value].filter(Boolean).length)
const statusOptions = computed(() => [
  { value: '', label: t('companyDirectory.statusLabel') },
  { value: 'verified', label: t('companyDirectory.verifiedOnly') },
  { value: 'featured', label: t('companyDirectory.featuredOnly') },
  { value: 'both', label: t('companyDirectory.verifiedAndFeatured') },
])
const sortOptions = computed(() => [
  { value: 'recommended', label: t('companyDirectory.sortRecommended') },
  { value: 'campaigns', label: t('companyDirectory.sortCampaigns') },
  { value: 'name', label: t('companyDirectory.sortName') },
])
watch(() => route.query.q, (value) => { search.value = queryString(value) })
watch(locale, loadFacets)
onMounted(loadFacets)
onBeforeUnmount(() => { disposed = true; facetVersion += 1 })
function queryString(value) { return typeof value === 'string' ? value : '' }
async function loadFacets() {
  const version = ++facetVersion
  facetError.value = ''
  try {
    const response = await apiGet('/companies/filters', { locale: locale.value })
    if (!disposed && version === facetVersion) facets.value = response.data
  } catch (cause) {
    if (!disposed && version === facetVersion) facetError.value = cause.message
  }
}
function clearFilters() {
  selectedIndustries.value = []
  city.value = ''
  selectedCountries.value = []
  verified.value = false
  featured.value = false
}
</script>

<template>
  <section class="page-width company-directory">
    <DirectoryHero>
        <p class="eyebrow">{{ t('companyDirectory.eyebrow') }}</p>
        <h1>{{ t('companyDirectory.title') }}</h1>
        <p>{{ t('companyDirectory.description') }}</p>
      <template #art><div class="company-directory__hero-art" aria-hidden="true">
        <img class="company-directory__hero-photo" src="/images/companies-hero.webp" alt="" width="900" height="600" fetchpriority="high" />
        <img class="company-directory__hero-circle company-directory__hero-circle--creator" src="/images/companies-photographer.webp" alt="" />
        <picture class="company-directory__hero-circle company-directory__hero-circle--travel">
          <source media="(max-width: 760px)" srcset="/images/companies-hero.webp" />
          <img src="https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?auto=format&amp;fit=crop&amp;w=320&amp;q=85" alt="" />
        </picture>
        <span class="company-directory__hero-badge"><Sparkles :size="20" /><small>{{ t('companyDirectory.heroBadge') }}</small></span>
      </div>
      </template>
    </DirectoryHero>

    <DirectoryToolbar v-model:search="search" v-model:filters-open="filtersOpen" :search-label="t('companyDirectory.searchLabel')" :search-placeholder="t('companyDirectory.search')" :filters-label="t('companyDirectory.filterButton')" filters-id="company-filters" :active-filter-count="activeFilterCount">
      <DirectorySearch v-model="city" :icon="false" :placeholder="t('companyDirectory.locationPlaceholder')" :label="t('companyDirectory.cityLabel')" />
      <SingleSelect v-model="status" :options="statusOptions" :label="t('companyDirectory.statusLabel')" :show-label="false" />
      <SingleSelect v-model="sort" :options="sortOptions" :label="t('companyDirectory.sortLabel')" :show-label="false" />
    </DirectoryToolbar>

    <div class="company-directory__content">
      <DirectoryFilterPanel id="company-filters" v-model="filtersOpen" :title="t('companyDirectory.filterTitle')" :clear-label="t('companyDirectory.clearFilters')" :apply-label="t('companyDirectory.applyFilters', { count: total })" @clear="clearFilters">
        <StatusMessage v-if="facetError" variant="error">{{ facetError }} <button type="button" @click="loadFacets">{{ t('companyDirectory.retryFilters') }}</button></StatusMessage>
        <fieldset>
          <MultiSelect
              v-model="selectedIndustries"
              :options="facets.industries"
              :label="t('companyDirectory.industryLabel')"
              :placeholder="t('companyDirectory.industryLabel')"
              :search-placeholder="t('companyDirectory.searchIndustries')"
              :no-results-label="t('companyDirectory.noIndustries')"
              :remove-label="t('account.remove')"
          />
        </fieldset>
        <fieldset>
          <MultiSelect
            v-model="selectedCountries"
            :options="countryOptions"
            :label="t('companyDirectory.countryLabel')"
            :placeholder="t('companyDirectory.countryLabel')"
            :search-placeholder="t('companyDirectory.countryPlaceholder')"
            :no-results-label="t('auth.noCountriesFound')"
            :remove-label="t('account.remove')"
          />
          <DirectorySearch class="company-directory__panel-city" v-model="city" :icon="false" :placeholder="t('companyDirectory.locationPlaceholder')" :label="t('companyDirectory.cityLabel')" />
        </fieldset>
        <fieldset>
          <SingleSelect v-model="status" :options="statusOptions" :label="t('companyDirectory.statusLabel')" />
        </fieldset>
        <template #mobile><fieldset>
          <SingleSelect v-model="sort" :options="sortOptions" :label="t('companyDirectory.sortLabel')" />
        </fieldset>
        </template>
      </DirectoryFilterPanel>

      <div class="company-directory__results">
        <div class="company-directory__results-head">
          <p aria-live="polite">{{ t('companyDirectory.resultCount', { count: total }) }}</p>
          <div class="company-directory__view-options" :aria-label="t('companyDirectory.layoutLabel')">
            <button type="button" :aria-pressed="layout === 'grid'" @click="layout = 'grid'"><LayoutGrid :size="16" aria-hidden="true" />{{ t('companyDirectory.grid') }}</button>
            <button type="button" :aria-pressed="layout === 'list'" @click="layout = 'list'"><List :size="16" aria-hidden="true" />{{ t('companyDirectory.list') }}</button>
          </div>
        </div>
        <StatusMessage v-if="!loading && !error && !companies.length" variant="empty">{{ t('companyDirectory.empty') }}</StatusMessage>
        <CardGrid v-if="companies.length || loading" kind="company" layout="directory" :class="{ 'company-directory__grid--list': layout === 'list' }" :aria-busy="loading">
          <CompanyCard v-for="company in companies" :key="company.id" :company="company" />
          <DirectorySkeletonCard v-for="index in loading ? (companies.length ? 3 : 6) : 0" :key="`loading-${index}`" kind="company" />
        </CardGrid>
        <DirectoryLoadMore :loading="loading" :error="error" :has-more="hasMore" :count="companies.length" @load="loadMore" />
      </div>
    </div>
  </section>
</template>

<style lang="scss" src="../scss/views/CompaniesView.scss"></style>
