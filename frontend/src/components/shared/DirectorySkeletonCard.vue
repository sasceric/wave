<script setup>
import '../../scss/components/creators/CreatorCard.scss'
import '../../scss/components/campaigns/CampaignCard.scss'
import '../../scss/components/companies/CompanyCard.scss'
defineProps({ kind: { type: String, required: true } })
</script>

<template>
  <div v-if="kind === 'creator'" class="creator-card directory-skeleton directory-skeleton--creator" aria-hidden="true">
    <div class="creator-card__media">
      <div class="creator-card__details">
        <span class="skeleton-line skeleton-line--wide"></span>
        <span class="skeleton-line skeleton-line--short"></span>
      </div>
    </div>
  </div>
  <div v-else-if="kind === 'campaign'" class="campaign-card directory-skeleton directory-skeleton--campaign" aria-hidden="true">
    <div class="campaign-card__cover skeleton-surface"></div>
    <div class="campaign-card__panel">
      <span class="skeleton-line skeleton-line--short"></span>
      <span class="skeleton-line skeleton-line--wide"></span>
      <span class="skeleton-line"></span>
      <span class="skeleton-line skeleton-line--short"></span>
      <span class="skeleton-line"></span>
    </div>
    <div class="campaign-card__footer">
      <span class="skeleton-line"></span>
      <span class="skeleton-button"></span>
    </div>
  </div>
  <div v-else class="company-directory-card directory-skeleton directory-skeleton--company" aria-hidden="true">
    <div class="company-directory-card__logo skeleton-surface"></div>
    <div class="company-directory-card__content">
      <span class="skeleton-line skeleton-line--wide"></span>
      <span class="skeleton-line"></span>
      <span class="skeleton-line skeleton-line--short"></span>
      <span class="skeleton-button"></span>
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/DirectorySkeletonCard.scss"></style>
