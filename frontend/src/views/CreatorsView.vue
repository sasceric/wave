<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import CreatorCard from '../components/creators/CreatorCard.vue'
import DirectoryFilters from '../components/shared/DirectoryFilters.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useDirectoryData } from '../composables/useDirectoryData'
import { SOCIAL_PLATFORMS } from '../lib/marketplace'

const { t, locale } = useI18n()
const route = useRoute()
const search = ref(queryString(route.query.q))
const category = ref('')
const platform = ref(normalizePlatform(route.query.platform))
const { items: creators, loading, error } = useDirectoryData('/creators', category, locale)

watch(() => route.query.q, (value) => {
  search.value = queryString(value)
})
watch(() => route.query.platform, (value) => {
  platform.value = normalizePlatform(value)
})

const visibleCreators = computed(() => {
  const query = search.value.trim().toLocaleLowerCase(locale.value)
  const selectedPlatform = platform.value.toLocaleLowerCase(locale.value)

  return creators.value.filter((creator) => {
    const matchesSearch = !query || [
      creator.displayName,
      creator.bio,
      creator.location,
      creator.category,
      creator.categoryLabel,
      ...creator.tags,
    ].join(' ').toLocaleLowerCase(locale.value).includes(query)
    const matchesPlatform = !selectedPlatform || creator.socialProfiles.some((profile) => (
      profile.platform.toLocaleLowerCase(locale.value) === selectedPlatform
    ))

    return matchesSearch && matchesPlatform
  })
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
      :count-label="t('creatorsPage.creatorCount', { count: visibleCreators.length })"
    />
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="loading && !creators.length">
      {{ t('creatorsPage.loading') }}
    </StatusMessage>
    <StatusMessage v-else-if="!visibleCreators.length" variant="empty">
      {{ t('creatorsPage.empty') }}
    </StatusMessage>
    <div v-else class="creator-grid">
      <CreatorCard
        v-for="creator in visibleCreators"
        :key="creator.id"
        :creator="creator"
      />
    </div>
  </section>
</template>
