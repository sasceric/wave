<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import DirectoryFilters from '../components/shared/DirectoryFilters.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useDirectoryData } from '../composables/useDirectoryData'

const { t, locale } = useI18n()
const search = ref('')
const category = ref('')
const { items: campaigns, loading, error } = useDirectoryData('/campaigns', category, locale)

const visibleCampaigns = computed(() => {
  const query = search.value.trim().toLocaleLowerCase(locale.value)
  if (!query) {
    return campaigns.value
  }

  return campaigns.value.filter((campaign) => [
    campaign.title,
    campaign.summary,
    campaign.description,
    campaign.category,
    campaign.categoryLabel,
    campaign.location,
    campaign.company.name,
    campaign.company.industry,
  ].join(' ').toLocaleLowerCase(locale.value).includes(query))
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
      :count-label="t('campaignsPage.campaignCount', { count: visibleCampaigns.length })"
    />
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="loading && !campaigns.length">
      {{ t('campaignsPage.loading') }}
    </StatusMessage>
    <StatusMessage v-else-if="!visibleCampaigns.length" variant="empty">
      {{ t('campaignsPage.empty') }}
    </StatusMessage>
    <div v-else class="campaign-grid campaign-grid--directory">
      <CampaignCard
        v-for="campaign in visibleCampaigns"
        :key="campaign.id"
        :campaign="campaign"
      />
    </div>
  </section>
</template>
