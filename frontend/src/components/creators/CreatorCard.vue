<script setup>
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import RatingSummary from '../reviews/RatingSummary.vue'
import '../../scss/components/creators/CreatorCard.scss'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Camera, CirclePlay, MapPin, Music2, UsersRound } from '@lucide/vue'
import RouterLink from '../shared/LocalizedLink.vue'
import { formatFollowers, formatMoney } from '../../lib/api'
import CardImage from '../shared/CardImage.vue'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({
  creator: { type: Object, required: true },
  imageSizes: { type: String, default: undefined },
})
const { t } = useI18n()

const primarySocial = computed(() => props.creator.socialProfiles?.[0] || null)
const socialIcon = computed(() => {
  const icons = {
    Instagram: Camera,
    TikTok: Music2,
    YouTube: CirclePlay,
  }

  return icons[primarySocial.value?.platform] || UsersRound
})
const categories = computed(() => (
  props.creator.categoryLabels?.length
    ? props.creator.categoryLabels
    : [props.creator.categoryLabel || props.creator.category]
))
const startingPrice = computed(() => {
  const prices = (props.creator.packages || [])
    .filter((packageItem) => Number.isFinite(packageItem.price) && packageItem.price > 0)

  if (!prices.length) {
    return ''
  }

  const currency = prices[0].currency || 'BAM'
  const comparablePrices = prices
    .filter((packageItem) => (packageItem.currency || 'BAM') === currency)
    .map((packageItem) => packageItem.price)

  return formatMoney(Math.min(...comparablePrices), currency)
})
</script>

<template>
  <RouterLink class="creator-card" :to="{ name: 'creator-profile', params: { slug: creator.slug } }">
    <div class="creator-card__media">
      <CardImage
        class="creator-card__image"
        :image="creator.avatarImage"
        :sizes="imageSizes"
        :src="creator.avatarUrl || CREATOR_PLACEHOLDER"
        :alt="creator.displayName"
      />
      <div v-if="creator.featured || creator.isPresentationDemo" class="creator-card__badges">
        <span v-if="creator.featured" class="creator-card__featured">{{ t('campaignCard.featured') }}</span>
        <PresentationDemoBadge v-if="creator.isPresentationDemo" overlay />
      </div>
      <span
        v-if="primarySocial"
        class="creator-card__metric"
        :aria-label="`${primarySocial.platform}: ${formatFollowers(primarySocial.followers)}`"
      >
        <component :is="socialIcon" :size="14" stroke-width="2" aria-hidden="true" />
        {{ formatFollowers(primarySocial.followers) }}
      </span>
    </div>
    <div class="creator-card__details">
      <div class="creator-card__heading">
        <h3>{{ creator.displayName }}</h3>
        <strong v-if="startingPrice" class="creator-card__price">{{ startingPrice }}</strong>
      </div>
      <p v-if="creator.city" class="creator-card__place"><MapPin :size="13" aria-hidden="true" /><span>{{ creator.city }}</span></p>
      <RatingSummary v-if="creator.rating?.reviewCount" class="creator-card__rating" :rating="creator.rating" compact />
      <p class="creator-card__categories">{{ categories.join(', ') }}</p>
    </div>
  </RouterLink>
</template>
