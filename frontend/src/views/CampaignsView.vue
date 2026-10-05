<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import DirectoryFilters from '../components/shared/DirectoryFilters.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useDirectoryData } from '../composables/useDirectoryData'

const { t, locale } = useI18n()
const search = ref('')
const category = ref('')
const page = ref(1)
const pageSize = ref(30)
const { items: campaigns, total, loading, error } = useDirectoryData(
  '/campaigns',
  locale,
  page,
  pageSize,
  { q: search, category },
)

watch([search, category], () => {
  page.value = 1
})
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
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="loading && !campaigns.length">
      {{ t('campaignsPage.loading') }}
    </StatusMessage>
    <StatusMessage v-else-if="!campaigns.length" variant="empty">
      {{ t('campaignsPage.empty') }}
    </StatusMessage>
    <div v-else class="campaign-grid campaign-grid--directory">
      <CampaignCard
        v-for="campaign in campaigns"
        :key="campaign.id"
        :campaign="campaign"
      />
    </div>
    <DirectoryPagination
      :page="page"
      :page-size="pageSize"
      :total="total"
      @update:page="page = $event"
      @update:page-size="pageSize = $event"
    />
  </section>
</template>
