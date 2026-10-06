<script setup>
import { computed } from 'vue'
import { Camera, CirclePlay, Music2, UsersRound } from '@lucide/vue'
import RouterLink from '../shared/LocalizedLink.vue'
import { formatFollowers, formatMoney } from '../../lib/api'
import WaveLogo from '../shared/WaveLogo.vue'
import CardImage from '../shared/CardImage.vue'

const props = defineProps({
  creator: { type: Object, required: true },
  imageSizes: { type: String, default: undefined },
})

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
        v-if="creator.avatarImage || creator.avatarUrl"
        class="creator-card__image"
        :image="creator.avatarImage"
        :sizes="imageSizes"
        :src="creator.avatarUrl"
        :alt="creator.displayName"
      />
      <div v-else class="creator-card__image creator-card__placeholder">
        <WaveLogo mark class="creator-card__placeholder-logo" />
      </div>
      <div class="creator-card__shade"></div>
      <span
        v-if="primarySocial"
        class="creator-card__metric"
        :aria-label="`${primarySocial.platform}: ${formatFollowers(primarySocial.followers)}`"
      >
        <component :is="socialIcon" :size="14" stroke-width="2" aria-hidden="true" />
        {{ formatFollowers(primarySocial.followers) }}
      </span>
      <div class="creator-card__details">
        <div class="creator-card__heading">
          <h3>{{ creator.displayName }}</h3>
          <strong v-if="startingPrice" class="creator-card__price">{{ startingPrice }}</strong>
        </div>
        <p class="creator-card__categories">{{ categories.join(', ') }}</p>
      </div>
    </div>
  </RouterLink>
</template>
