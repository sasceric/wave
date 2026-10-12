<script setup>
import { computed } from 'vue'
import { Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
const props = defineProps({ rating: { type: Object, default: () => ({}) }, compact: { type: Boolean, default: false } })
const { t } = useI18n()
const average = computed(() => props.rating?.average == null ? null : Number(props.rating.average).toFixed(1))
</script>
<template>
  <span class="rating-summary" :class="{ 'rating-summary--compact': compact }" :title="t('reviews.ratingHint', { count: rating?.reviewCount || 0 })">
    <Star :size="14" aria-hidden="true" /><strong>{{ average ?? '—' }}</strong>
    <span>{{ compact ? `(${rating?.reviewCount || 0})` : t('reviews.completed', { count: rating?.completedCampaigns || 0 }) }}</span>
  </span>
</template>
<style scoped lang="scss">
.rating-summary { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 5px; font-size: 12px; color: inherit; line-height: 1.6; strong { font-weight: 600; } svg { fill: currentColor; } }
</style>
