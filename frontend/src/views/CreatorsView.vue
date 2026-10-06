<script setup>
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import CreatorCard from '../components/creators/CreatorCard.vue'
import DirectoryFilters from '../components/shared/DirectoryFilters.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'
import { SOCIAL_PLATFORMS } from '../lib/marketplace'

const { t, locale } = useI18n()
const route = useRoute()
const search = ref(queryString(route.query.q))
const category = ref('')
const platform = ref(normalizePlatform(route.query.platform))
const { items: creators, total, loading, error, hasMore, loadMore } = useInfiniteDirectory(
  '/creators',
  locale,
  { q: search, category, platform },
)

watch(() => route.query.q, (value) => {
  search.value = queryString(value)
})
watch(() => route.query.platform, (value) => {
  platform.value = normalizePlatform(value)
})

function queryString(value) {
  return typeof value === 'string' ? value : ''
}

function normalizePlatform(value) {
  if (typeof value !== 'string') {
    return ''
  }

  return SOCIAL_PLATFORMS.find((item) => (
    item.toLocaleLowerCase() === value.toLocaleLowerCase()
  )) || ''
}
</script>

<template>
  <section class="page-hero page-width">
    <p class="eyebrow">{{ t('creatorsPage.eyebrow') }}</p>
    <h1>
      {{ t('creatorsPage.headlineLead') }}
      <em>{{ t('creatorsPage.headlineEmphasis') }}</em>
    </h1>
    <p>{{ t('creatorsPage.description') }}</p>
  </section>

  <section class="directory page-width">
    <DirectoryFilters
      v-model:search="search"
      v-model:category="category"
      v-model:platform="platform"
      :search-placeholder="t('creatorsPage.search')"
      :search-label="t('creatorsPage.searchLabel')"
      :category-label="t('creatorsPage.categoryLabel')"
      :platform-label="t('creatorsPage.platformLabel')"
      :platform-options="SOCIAL_PLATFORMS"
      :count-label="t('creatorsPage.creatorCount', { count: total })"
    />
    <StatusMessage v-if="!loading && !error && !creators.length" variant="empty">
      {{ t('creatorsPage.empty') }}
    </StatusMessage>
    <div v-if="creators.length || loading" class="creator-grid creator-grid--directory" :aria-busy="loading">
      <CreatorCard
        v-for="creator in creators"
        :key="creator.id"
        :creator="creator"
      />
      <DirectorySkeletonCard v-for="index in loading ? (creators.length ? 2 : 6) : 0" :key="`loading-${index}`" kind="creator" />
    </div>
    <DirectoryLoadMore
      :loading="loading"
      :error="error"
      :has-more="hasMore"
      :count="creators.length"
      @load="loadMore"
    />
  </section>
</template>
