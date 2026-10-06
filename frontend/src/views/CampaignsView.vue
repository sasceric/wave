<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import DirectoryFilters from '../components/shared/DirectoryFilters.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'

const { t, locale } = useI18n()
const search = ref('')
const category = ref('')
const { items: campaigns, total, loading, error, hasMore, loadMore } = useInfiniteDirectory(
  '/campaigns',
  locale,
  { q: search, category },
)
</script>

<template>
  <section class="page-hero page-width">
    <p class="eyebrow">{{ t('campaignsPage.eyebrow') }}</p>
    <h1>
      {{ t('campaignsPage.headlineLead') }}
      <em>{{ t('campaignsPage.headlineEmphasis') }}</em>
    </h1>
    <p>{{ t('campaignsPage.description') }}</p>
  </section>

  <section class="directory page-width">
    <DirectoryFilters
      v-model:search="search"
      v-model:category="category"
      :search-placeholder="t('campaignsPage.search')"
      :search-label="t('campaignsPage.searchLabel')"
      :category-label="t('campaignsPage.categoryLabel')"
      :count-label="t('campaignsPage.campaignCount', { count: total })"
    />
    <StatusMessage v-if="!loading && !error && !campaigns.length" variant="empty">
      {{ t('campaignsPage.empty') }}
    </StatusMessage>
    <div v-if="campaigns.length || loading" class="campaign-grid campaign-grid--directory" :aria-busy="loading">
      <CampaignCard
        v-for="campaign in campaigns"
        :key="campaign.id"
        :campaign="campaign"
      />
      <DirectorySkeletonCard v-for="index in loading ? (campaigns.length ? 2 : 6) : 0" :key="`loading-${index}`" kind="campaign" />
    </div>
    <DirectoryLoadMore
      :loading="loading"
      :error="error"
      :has-more="hasMore"
      :count="campaigns.length"
      @load="loadMore"
    />
  </section>
</template>
