<script setup>
import { computed } from 'vue'
import { Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
const props = defineProps({ rating: { type: Object, default: () => ({}) }, compact: { type: Boolean, default: false }, profile: { type: Boolean, default: false } })
const { t, locale } = useI18n()
const average = computed(() => props.rating?.average == null ? null : new Intl.NumberFormat(locale.value === 'bs' || locale.value === 'cnr' ? 'hr' : locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(props.rating.average)))
</script>
<template>
  <span class="rating-summary" :class="{ 'rating-summary--compact': compact, 'rating-summary--profile': profile }" :title="t('reviews.ratingHint', { count: rating?.reviewCount || 0 })">
    <Star :size="14" aria-hidden="true" /><strong>{{ average ?? '—' }}</strong>
    <span>{{ profile ? `· ${t('studio.reviewCount', { count: rating?.reviewCount || 0 })}` : compact ? `(${rating?.reviewCount || 0})` : t('reviews.completed', { count: rating?.completedCampaigns || 0 }) }}</span>
  </span>
</template>
<style scoped lang="scss">
.rating-summary { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 5px; font-size: 12px; color: inherit; line-height: 1.6; strong { font-weight: 600; } svg { fill: currentColor; } &--profile { color: var(--forest); svg { fill: var(--coral); stroke: var(--coral); } strong { font-weight: 500; } } }
</style>
