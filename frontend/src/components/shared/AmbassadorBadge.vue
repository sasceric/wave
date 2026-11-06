<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

// Pozovi kreatore: bronze (20), silver (35) or gold (50) ambassador. `compact` shows only "Ambasador" (cards).
const props = defineProps({
  tier: { type: Number, default: 0 },
  compact: { type: Boolean, default: false },
})
const { t } = useI18n()
const key = computed(() => ({ 20: 'bronze', 35: 'silver', 50: 'gold' })[props.tier] || '')
</script>

<template>
  <span v-if="key" class="ambassador-badge" :class="[`ambassador-badge--${key}`, { 'ambassador-badge--compact': compact }]" :title="t(`referrals.badge.${key}`)">
    <span class="ambassador-badge__medal" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.5l2.9 6 6.6.8-4.9 4.5 1.3 6.5L12 17l-5.9 3.3 1.3-6.5L2.5 9.3l6.6-.8z" /></svg></span>
    <span>{{ compact ? t('referrals.badge.short') : t(`referrals.badge.${key}`) }}</span>
  </span>
</template>

<style lang="scss" src="../../scss/components/shared/AmbassadorBadge.scss"></style>
