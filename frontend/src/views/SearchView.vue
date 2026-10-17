<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowRight } from '@lucide/vue'
import { apiGet } from '../lib/api'
import { localizedRouteName } from '../routePaths'
import { isSearchableQuery, SEARCH_MIN_LENGTH } from '../lib/searchQuery'
import { currentUser } from '../composables/useCurrentUser'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import SearchResultCard from '../components/shared/SearchResultCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
const route = useRoute(), router = useRouter()
const { t, locale } = useI18n()
const types = ['creators', 'campaigns', 'companies']
const query = computed(() => typeof route.query.q === 'string' ? route.query.q.trim().slice(0, 200) : '')
const canSearch = computed(() => isSearchableQuery(query.value))
const type = computed(() => types.includes(route.query.type) ? route.query.type : 'all')
const page = computed(() => Math.max(1, Math.min(40000000, Number.parseInt(route.query.page, 10) || 1)))
const counts = ref({}), items = ref([]), total = ref(0), loading = ref(false), error = ref('')
const tabs = computed(() => ['all', ...types].map(key => ({ key, label: t(`globalSearch.${key}`), count: key === 'all' ? types.every(value => counts.value[value] !== undefined) ? types.reduce((sum, value) => sum + counts.value[value], 0) : undefined : counts.value[key] })))
const groups = computed(() => (type.value === 'all' ? types : [type.value])
  .map(key => ({ key, label: t(`globalSearch.${key}`), total: counts.value[key] ?? 0, items: items.value.filter(item => item.kind === key) }))
  .filter(group => group.items.length))
let revision = 0
function navigate(nextType, nextPage = 1) {
  router.push({ name: localizedRouteName('search', locale.value), query: { ...(query.value ? { q: query.value } : {}), ...(nextType !== 'all' ? { type: nextType } : {}), ...(nextPage > 1 ? { page: nextPage } : {}) } })
}
async function load() {
  const id = ++revision
  items.value = []; error.value = ''; counts.value = {}; total.value = 0
  if (!canSearch.value) { loading.value = false; return }
  loading.value = true
  try {
    const params = new URLSearchParams({ q: query.value, type: type.value, page: String(page.value) })
    const response = await apiGet(`/search?${params}`, { locale: locale.value })
    if (id !== revision) return
    counts.value = response.meta.counts
    total.value = response.meta.total
    items.value = response.data
    const lastPage = Math.max(1, Math.ceil(total.value / 25))
    if (page.value > lastPage) navigate(type.value, lastPage)
  } catch (cause) { if (id === revision) error.value = cause.message || t('globalSearch.error') }
  finally { if (id === revision) loading.value = false }
}
watch([query, type, page, locale, () => currentUser.value?.id], load, { immediate: true })
onMounted(() => window.addEventListener('wave:safety-changed', load))
onBeforeUnmount(() => { revision++; window.removeEventListener('wave:safety-changed', load) })
</script>
<template>
  <main class="search-page page-width">
    <nav class="search-page__breadcrumbs" :aria-label="t('globalSearch.breadcrumb')"><LocalizedLink :to="{ name: 'home' }">{{ t('globalSearch.home') }}</LocalizedLink><span aria-hidden="true">›</span><span>{{ t('globalSearch.pageTitle') }}</span></nav>
    <h1>{{ query ? t('globalSearch.resultsFor', { query }) : t('globalSearch.pageTitle') }}</h1>
    <p v-if="canSearch" class="search-page__intro">{{ loading ? t('globalSearch.loading') : error ? t('globalSearch.error') : t('globalSearch.resultCount', { count: total }) }}</p>
    <ProfileTabs :model-value="type" :items="tabs" :label="t('globalSearch.type')" id-prefix="search" @update:model-value="navigate($event)" />
    <LoadingSkeleton v-if="loading" variant="rows" :count="3" />
    <StatusMessage v-else-if="error" variant="error">{{ error }} <button class="button button--outline" type="button" @click="load">{{ t('globalSearch.retry') }}</button></StatusMessage>
    <section v-else-if="!items.length" class="search-page__empty" aria-labelledby="search-empty-title">
      <svg class="search-page__empty-art" viewBox="0 0 176 160" aria-hidden="true" focusable="false">
        <path class="search-page__empty-shape" d="M24 108C5 74 25 27 61 20c39-8 70 23 82 55s3 69-30 76c-19 4-35-3-49-1-16 3-29 1-24-14-5-9-11-18-16-28Z" />
        <g fill="none" stroke-linecap="round" stroke-width="4">
          <circle cx="83" cy="85" r="21" stroke="currentColor" />
          <path d="m99 101 20 20" stroke="currentColor" />
          <path d="M129 14v16m29-5-12 12m24 17h-16" class="search-page__empty-rays" />
        </g>
      </svg>
      <h2 id="search-empty-title">{{ t(canSearch ? 'globalSearch.empty' : 'globalSearch.startTitle') }}</h2>
      <p v-if="query && !canSearch">{{ t('globalSearch.minimumCharacters', { count: SEARCH_MIN_LENGTH }) }}</p>
      <p v-else>{{ t(canSearch ? 'globalSearch.emptyHint' : 'globalSearch.startDescription') }}</p>
      <div class="search-page__empty-actions">
        <LocalizedLink class="button button--dark" :to="{ name: 'creators' }">{{ t('app.creators') }}<ArrowRight :size="18" aria-hidden="true" /></LocalizedLink>
        <LocalizedLink class="button button--outline" :to="{ name: 'campaigns' }">{{ t('globalSearch.browseCampaigns') }}<ArrowRight :size="18" aria-hidden="true" /></LocalizedLink>
      </div>
    </section>
    <div v-else class="search-page__groups">
      <section v-for="group in groups" :key="group.key" class="search-page__group" :aria-labelledby="`search-group-${group.key}`">
        <header class="search-page__group-heading">
          <h2 :id="`search-group-${group.key}`">{{ group.label }} <span>({{ group.total }})</span></h2>
          <LocalizedLink v-if="type === 'all'" :to="{ name: 'search', query: { q: query, type: group.key } }" :aria-label="t('globalSearch.viewAllCategory', { category: group.label, count: group.total })">{{ t('globalSearch.viewAll') }}<ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
        </header>
        <div class="search-page__grid"><SearchResultCard v-for="item in group.items" :key="item.id" :item="item" /></div>
      </section>
    </div>
    <DirectoryPagination v-if="!loading && !error" :page="page" :page-size="25" :total="total" :show-page-size="false" @update:page="navigate(type, $event)" />
  </main>
</template>
<style lang="scss" src="../scss/views/SearchView.scss"></style>
