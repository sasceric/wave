<script setup>
import { useI18n } from 'vue-i18n'
import { UsersRound } from '@lucide/vue'

defineProps({ campaign: { type: Object, required: true }, compact: { type: Boolean, default: false }, showIcon: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <span class="campaign-hiring-progress" :role="compact ? 'img' : undefined" :aria-label="compact ? t('campaignCard.hiringProgress', { hired: campaign.hiredCount ?? 0, total: campaign.creatorCount }) : undefined">
    <UsersRound v-if="compact || showIcon" :size="14" aria-hidden="true" />
    <template v-if="compact"><span aria-hidden="true">{{ campaign.hiredCount ?? 0 }}/{{ campaign.creatorCount }}</span></template>
    <template v-else>{{ t('campaignCard.hiringProgress', { hired: campaign.hiredCount ?? 0, total: campaign.creatorCount }) }}</template>
  </span>
</template>

<style scoped lang="scss">
.campaign-hiring-progress {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 400;
  line-height: 1.5;

  svg { flex-shrink: 0; }
}
</style>
