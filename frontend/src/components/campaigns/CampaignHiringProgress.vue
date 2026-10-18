<script setup>
import { useI18n } from 'vue-i18n'
import { UsersRound } from '@lucide/vue'

defineProps({ campaign: { type: Object, required: true }, compact: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <span class="campaign-hiring-progress" :role="compact ? 'img' : undefined" :aria-label="compact ? t('campaignCard.hiringProgress', { hired: campaign.hiredCount ?? 0, total: campaign.creatorCount }) : undefined">
    <template v-if="compact"><UsersRound :size="14" aria-hidden="true" /><span aria-hidden="true">{{ campaign.hiredCount ?? 0 }}/{{ campaign.creatorCount }}</span></template>
    <template v-else>{{ t('campaignCard.hiringProgress', { hired: campaign.hiredCount ?? 0, total: campaign.creatorCount }) }}</template>
  </span>
</template>
