<script setup>
import { Check } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
defineProps({ item: { type: Object, required: true } })
const { t } = useI18n()
</script>
<template>
  <ul v-if="item.deliverables?.length || item.deliveryDays || item.revisions != null || item.usage" class="creator-package-facts">
    <li v-if="item.mode">
      <Check :size="13" aria-hidden="true" />
      <span>{{ t(`studio.${item.mode}`) }}</span>
    </li>
    <li v-for="(row, index) in item.deliverables || []" :key="index">
      <Check :size="13" aria-hidden="true" />
      <span>
        {{ row.quantity }}
        ×
        {{ t(`studio.${row.format}`) }}
        <template v-if="row.duration"> · {{ row.duration }} {{ t('studio.seconds') }}</template>
      </span>
    </li>
    <li v-if="item.deliveryDays">
      <Check :size="13" aria-hidden="true" />
      <span>{{ t('studio.deliveryDescription', { count: item.deliveryDays }) }}</span>
    </li>
    <li v-if="item.revisions != null">
      <Check :size="13" aria-hidden="true" />
      <span>{{ t('studio.revisionDescription', { count: item.revisions }) }}</span>
    </li>
    <li v-if="item.usage">
      <Check :size="13" aria-hidden="true" />
      <span>
        {{ t(`studio.${item.usage}`) }}
        <template v-if="item.usage === 'ads' && item.usageDays"> · {{ item.usageDays }} {{ t('studio.days') }}</template>
      </span>
    </li>
  </ul>
</template>
<style scoped lang="scss">
.creator-package-facts { padding: 0; margin: 10px 0; list-style: none; font-size: 11px; line-height: 1.7; color: var(--muted); li { display: flex; align-items: baseline; gap: 7px; padding: 3px 0; } svg { flex: 0 0 13px; align-self: flex-start; margin-top: 3px; color: var(--forest); } }
</style>
