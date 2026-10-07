<script setup>
import '../../scss/components/companies/CompanyCard.scss'
import { ArrowRight, BadgeCheck, MapPin, Megaphone } from '@lucide/vue'
import RouterLink from '../shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import WaveLogo from '../shared/WaveLogo.vue'
import CardImage from '../shared/CardImage.vue'

defineProps({ company: { type: Object, required: true } })
const { t } = useI18n()
</script>

<template>
  <article class="company-directory-card">
    <div class="company-directory-card__visual" aria-hidden="true">
      <CardImage :image="company.coverImage" :src="company.coverUrl || '/images/company-cover.webp'" alt="" sizes="(max-width: 760px) calc(100vw - 36px), (max-width: 1150px) 35vw, 25vw" />
    </div>
    <div class="company-directory-card__body">
      <div class="company-directory-card__identity">
        <div class="company-directory-card__logo">
          <CardImage v-if="company.logoImage || company.logoUrl" :image="company.logoImage" :src="company.logoUrl" :alt="company.name" sizes="58px" />
          <WaveLogo v-else mark />
        </div>
        <span v-if="company.verified" class="company-directory-card__verified" :aria-label="t('companyDirectory.verified')"><BadgeCheck :size="15" aria-hidden="true" /></span>
        <div class="company-directory-card__heading"><h2>{{ company.name }}</h2><p>{{ company.industryLabels?.join(', ') || company.industry }}</p></div>
      </div>
      <p v-if="company.summary || company.about" class="company-directory-card__description">{{ company.summary || company.about || '' }}</p>
      <div class="company-directory-card__footer">
        <span v-if="company.city" class="company-directory-card__meta"><MapPin :size="14" aria-hidden="true" />{{ company.city }}</span>
        <span class="company-directory-card__meta" :aria-label="`${t('companyDirectory.openCampaigns')}: ${company.availableCampaignCount ?? 0}`"><Megaphone :size="14" aria-hidden="true" />{{ company.availableCampaignCount ?? 0 }}</span>
        <RouterLink class="company-directory-card__link" :to="{ name: 'company-profile', params: { slug: company.slug } }">{{ t('companyDirectory.viewProfile') }}<ArrowRight :size="13" aria-hidden="true" /></RouterLink>
      </div>
    </div>
  </article>
</template>
