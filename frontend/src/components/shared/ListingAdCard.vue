<script setup>
import { ArrowUpRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import CardImage from './CardImage.vue'
import { AD_PALETTES } from '../../lib/listingAds'
defineProps({ ad: { type: Object, required: true }, preview: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <article class="listing-ad" :class="[`listing-ad--${AD_PALETTES.includes(ad.palette) ? ad.palette : 'forest'}`, { 'listing-ad--wide': ad.span === 2, 'listing-ad--image': ad.imageUrl }]">
    <div v-if="ad.imageUrl" class="listing-ad__image"><CardImage :src="ad.imageUrl" :image="ad.image" alt="" sizes="(max-width: 760px) 100vw, 600px" /></div>
    <div class="listing-ad__content">
      <span class="listing-ad__label">{{ t('listingAds.adLabel') }}</span>
      <h3>{{ ad.title }}</h3>
      <p v-if="ad.body">{{ ad.body }}</p>
      <span v-if="preview && ad.buttonLabel" class="listing-ad__link">{{ ad.buttonLabel }}<ArrowUpRight :size="18" aria-hidden="true" /></span>
      <a v-else-if="ad.url && ad.buttonLabel" class="listing-ad__link" :href="ad.url" rel="sponsored noopener" :target="ad.url.startsWith('/') ? undefined : '_blank'">{{ ad.buttonLabel }}<ArrowUpRight :size="18" aria-hidden="true" /></a>
      <span v-if="preview && ad.helpLabel" class="listing-ad__help">{{ ad.helpLabel }}</span>
      <a v-else-if="ad.helpUrl && ad.helpLabel" class="listing-ad__help" :href="ad.helpUrl" rel="sponsored noopener" :target="ad.helpUrl.startsWith('/') ? undefined : '_blank'">{{ ad.helpLabel }}</a>
    </div>
  </article>
</template>

<style lang="scss" src="../../scss/components/shared/ListingAdCard.scss"></style>
