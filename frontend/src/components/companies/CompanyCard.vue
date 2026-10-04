<script setup>
import { ArrowRight, Megaphone } from '@lucide/vue'
import RouterLink from '../shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import WaveLogo from '../shared/WaveLogo.vue'

defineProps({
  company: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
  <article class="company-directory-card">
    <div class="company-directory-card__identity">
      <div class="company-directory-card__logo">
        <img v-if="company.logoUrl" :src="company.logoUrl" :alt="company.name" loading="lazy" />
        <WaveLogo v-else mark />
      </div>
      <span
        v-if="company.verified"
        class="company-directory-card__verified"
        :aria-label="t('campaignCard.verified')"
      >✓</span>
    </div>
    <div class="company-directory-card__content">
      <div class="company-directory-card__heading">
        <h2>{{ company.name }}</h2>
        <span v-if="company.featured" class="company-directory-card__featured">
          {{ t('companyDirectory.featured') }}
        </span>
      </div>
      <p class="company-directory-card__description">{{ company.industry }}</p>
      <div class="company-directory-card__footer">
        <span class="company-directory-card__campaign-count">
          <Megaphone :size="15" aria-hidden="true" />
          {{ t('companyDirectory.openCampaigns') }}
          <strong>{{ company.availableCampaignCount ?? 0 }}</strong>
        </span>
        <RouterLink
          class="company-directory-card__link button button--outline"
          :to="{ name: 'company-profile', params: { slug: company.slug } }"
        >
          {{ t('companyDirectory.viewProfile') }}
          <ArrowRight :size="15" aria-hidden="true" />
        </RouterLink>
      </div>
    </div>
  </article>
</template>
