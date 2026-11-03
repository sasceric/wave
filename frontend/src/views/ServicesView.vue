<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import DirectoryFilterPanel from '../components/shared/DirectoryFilterPanel.vue'
import { ArrowRight, ShoppingBag } from '@lucide/vue'
import DirectoryHero from '../components/shared/DirectoryHero.vue'
import DirectoryHeroCollage from '../components/shared/DirectoryHeroCollage.vue'
import DirectorySearch from '../components/shared/DirectorySearch.vue'
import DirectoryToolbar from '../components/shared/DirectoryToolbar.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import SkeletonBlock from '../components/shared/SkeletonBlock.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import ServicePackageCard from '../components/services/ServicePackageCard.vue'
import ServiceTypeChips from '../components/services/ServiceTypeChips.vue'
import ServicePackageSheet from '../components/services/ServicePackageSheet.vue'
import ServicePackageSkeleton from '../components/services/ServicePackageSkeleton.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet } from '../lib/api'
import { localizedPath, localizedRouteName } from '../routePaths'
import { SERVICE_PAGE_SLUGS, serviceTypeForSlug, serviceTypeLocation } from '../lib/serviceTypes'

const PAGE_SIZE = 24
const TYPES = ['', 'ugc', 'instagram', 'tiktok', 'photo', 'other', 'agreement']
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const items = ref([])
const counts = ref(null)
const total = ref(0)
const loading = ref(false)
// Load more keeps the list; a filter change replaces it, so show placeholders instead of stale cards.
const appending = ref(false)
const refreshing = computed(() => loading.value && !appending.value)
const error = ref('')
const filters = ref(readQuery())
const filtersOpen = ref(false)
const selected = ref(null)
let request = 0

function readQuery() {
  const value = (key, allowed) => (typeof route.query[key] === 'string' && allowed.includes(route.query[key]) ? route.query[key] : '')
  return {
    type: typeof route.params.type === 'string' ? serviceTypeForSlug(route.params.type) : value('type', TYPES),
    price: value('price', ['', 'to100', '100to300', 'over300']),
    sort: value('sort', ['', 'price', 'newest']),
    city: typeof route.query.city === 'string' ? route.query.city.slice(0, 80) : '',
    q: typeof route.query.q === 'string' ? route.query.q.slice(0, 120) : '',
  }
}

const priceOptions = computed(() => ['', 'to100', '100to300', 'over300'].map(value => ({ value, label: t(`services.price.${value || 'all'}`) })))
const sortOptions = computed(() => ['', 'price', 'newest'].map(value => ({ value, label: t(`services.sort.${value || 'recommended'}`) })))
const typeOptions = computed(() => TYPES.map(value => ({ value, label: value ? t(`services.types.${value}`) : t('services.allTypes') })))
const activeFilterCount = computed(() => [filters.value.type, filters.value.price].filter(Boolean).length)
function clearFilters() { filters.value = { ...filters.value, type: '', price: '', city: '', q: '' } }
const heroCopy = computed(() => (route.meta.routeName === 'services-type' && SERVICE_PAGE_SLUGS[filters.value.type] ? `services.typePages.${filters.value.type}` : 'services'))
const linkQuery = computed(() => Object.fromEntries(Object.entries(filters.value).filter(([key, entry]) => entry && key !== 'type')))
const hasMore = computed(() => items.value.length < total.value)
const campaignLink = computed(() => (currentUser.value?.accountType === 'company'
  ? { name: 'account-campaign-create' }
  : { name: 'register', query: { accountType: 'company', returnTo: localizedPath('account-campaign-create', locale.value) } }))

async function load(append = false) {
  const id = ++request
  loading.value = true
  appending.value = append
  error.value = ''
  const query = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(append ? items.value.length : 0) })
  for (const [key, value] of Object.entries(filters.value)) if (value) query.set(key, value)
  try {
    const response = await apiGet(`/services?${query}`)
    if (id !== request) return
    items.value = append ? [...items.value, ...response.data] : response.data
    total.value = response.meta.total
    counts.value = response.meta.counts
  } catch (cause) {
    if (id === request) error.value = cause.message
  } finally {
    if (id === request) loading.value = false
  }
}

// Each page type has its own URL (/usluge/ugc-video); price, city, search and sort stay in the query.
function locationFor(value) {
  const query = Object.fromEntries(Object.entries(value).filter(([key, entry]) => entry && key !== 'type'))
  const target = serviceTypeLocation(value.type, query)
  return { ...target, name: localizedRouteName(target.name, locale.value) }
}

let lastType = filters.value.type
watch(filters, (value) => {
  const target = router.resolve(locationFor(value))
  if (target.fullPath !== route.fullPath) {
    if (value.type !== lastType) router.push(target)
    else router.replace(target)
  }
  lastType = value.type
  load()
}, { deep: true })
// Back/forward and chip links change the URL first; follow it.
watch(() => route.fullPath, () => {
  if (!['services', 'services-type'].includes(route.meta.routeName)) return
  const next = readQuery()
  if (JSON.stringify(next) !== JSON.stringify(filters.value)) filters.value = next
})
watch(locale, () => load())
onMounted(() => {
  const invalidSlug = typeof route.params.type === 'string' && !serviceTypeForSlug(route.params.type)
  const legacyType = typeof route.query.type === 'string' && SERVICE_PAGE_SLUGS[route.query.type]
  if (invalidSlug || legacyType) router.replace(router.resolve(locationFor(filters.value)))
  load()
})
</script>

<template>
  <section class="page-width services-page">
    <DirectoryHero>
      <p class="eyebrow">{{ t('services.eyebrow') }}</p>
      <h1>{{ t(`${heroCopy}.headlineLead`) }} <em>{{ t(`${heroCopy}.headlineEmphasis`) }}</em></h1>
      <p>{{ t(heroCopy === 'services' ? 'services.description' : `${heroCopy}.intro`) }}</p>
      <template #art>
        <DirectoryHeroCollage main-image="/images/how-it-works-creator.webp" secondary-image="/images/companies-photographer.webp" detail-image="/images/bag.webp">
          <ShoppingBag :size="26" /><span>{{ t('services.heroBadge') }}</span><ArrowRight :size="17" />
        </DirectoryHeroCollage>
      </template>
    </DirectoryHero>

    <DirectoryToolbar v-model:search="filters.q" v-model:filters-open="filtersOpen" :search-label="t('services.searchLabel')" :search-placeholder="t('services.searchPlaceholder')" :filters-label="t('companyDirectory.filterButton')" filters-id="service-filters" :active-filter-count="activeFilterCount" :control-columns="2">
      <DirectorySearch v-model="filters.city" :icon="false" :placeholder="t('companyDirectory.locationPlaceholder')" :label="t('companyDirectory.cityLabel')" />
      <SingleSelect v-model="filters.sort" :options="sortOptions" :label="t('services.sortLabel')" :show-label="false" />
      <template #extra><ServiceTypeChips v-model="filters.type" class="services-page__chips" :options="TYPES" :counts="counts" :link-query="linkQuery" links /></template>
    </DirectoryToolbar>

    <div class="services-page__content">
      <div class="services-page__sidebar">
        <DirectoryFilterPanel id="service-filters" v-model="filtersOpen" :title="t('companyDirectory.filterTitle')" :clear-label="t('companyDirectory.clearFilters')" :apply-label="t('companyDirectory.applyFilters', { count: total })" @clear="clearFilters">
          <fieldset><SingleSelect v-model="filters.type" :label="t('services.typeLabel')" :options="typeOptions" /></fieldset>
          <fieldset><SingleSelect v-model="filters.price" :label="t('services.priceLabel')" :options="priceOptions" /></fieldset>
          <template #mobile><fieldset><SingleSelect v-model="filters.sort" :label="t('services.sortLabel')" :options="sortOptions" /></fieldset></template>
        </DirectoryFilterPanel>
        <aside class="services-page__campaign">
          <div>
              <h2>{{ t('services.campaignTitle') }}</h2>
              <p>{{ t('services.campaignText') }}</p>
            </div>
            <LocalizedLink class="button button--light" :to="campaignLink">{{ t('services.campaignAction') }}</LocalizedLink>
          </aside>
      </div>

      <div class="services-page__results">
        <p class="services-page__count" aria-live="polite">
          <SkeletonBlock v-if="refreshing" width="96px" />
          <template v-else>{{ t('services.count', { count: total }, total) }}</template>
        </p>
        <StatusMessage v-if="error && !items.length && !loading" variant="error">{{ error }}</StatusMessage>
        <StatusMessage v-else-if="!loading && !items.length" variant="empty">{{ t('services.empty') }}</StatusMessage>
        <ul class="service-grid" :aria-busy="loading">
          <li v-for="item in refreshing ? [] : items" :key="`${item.creator.slug}-${item.id}`"><ServicePackageCard :item="item" @open="selected = $event" /></li>
          <li v-for="index in loading ? (appending ? 4 : 8) : 0" :key="`loading-${index}`"><ServicePackageSkeleton /></li>
        </ul>
        <ServicePackageSheet :item="selected" @close="selected = null" />
        <DirectoryLoadMore v-if="items.length" :loading="loading" :has-more="hasMore" :error="error" :count="items.length" @load="load(true)" />

      </div>
    </div>
  </section>
</template>

<style lang="scss" src="../scss/components/services/ServicePackageGrid.scss"></style>
<style scoped lang="scss" src="../scss/views/ServicesView.scss"></style>
