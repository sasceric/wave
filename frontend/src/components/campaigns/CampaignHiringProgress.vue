<script setup>
import { useI18n } from 'vue-i18n'

defineProps({ campaign: { type: Object, required: true } })
const { t } = useI18n()
</script>

<template>
  <span class="campaign-hiring-progress">{{ t('campaignCard.hiringProgress', { hired: campaign.hiredCount ?? 0, total: campaign.creatorCount }) }}</span>
</template>
