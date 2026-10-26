<script setup>
import { Check, Circle } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDate } from '../../lib/api'
const props = defineProps({ row: { type: Object, required: true } })
const lastSent = computed(() => (props.row.profileReminders?.stages || []).map((stage) => stage.sentAt).filter(Boolean).sort().at(-1) || null)
defineEmits(['review'])
const { t } = useI18n()
</script>

<template>
  <button class="admin-profile-completion" type="button" :aria-label="`${t('adminProfiles.review')}: ${row.displayName || row.name || row.email}`" @click="$emit('review')">
    <span v-if="typeof row.profileStrength === 'number'" class="admin-profile-completion__strength" :class="{ 'is-ready': row.profileStrength >= 100 }">
      {{ t('profileStrength.title') }}<b>{{ row.profileStrength }}%</b>
      <i aria-hidden="true"><i :style="{ width: `${row.profileStrength}%` }" /></i>
    </span>
    <span v-if="row.profileReminders" class="admin-profile-completion__reminders">
      {{ t('adminProfiles.reminders.short', { sent: row.profileReminders.lastStage || 0, total: row.profileReminders.stages.length }) }}<template v-if="lastSent"> · {{ formatDate(lastSent) }}</template>
      <template v-if="row.profileReminders.status !== 'active'"> · {{ t(`adminProfiles.reminders.status.${row.profileReminders.status}`) }}</template>
    </span>
    <span v-for="(count, key) in row.completion" :key="key" :class="{ 'is-filled': count > 0 }" :title="t(count > 0 ? 'adminProfiles.filled' : 'adminProfiles.missing')">
      <Check v-if="count > 0" :size="12" aria-hidden="true" /><Circle v-else :size="11" aria-hidden="true" />
      {{ t(`adminProfiles.sections.${key}`) }}<b v-if="['networks', 'services', 'works', 'packages', 'faqs'].includes(key)">{{ count }}</b>
      <span class="sr-only">{{ t(count > 0 ? 'adminProfiles.filled' : 'adminProfiles.missing') }}</span>
    </span>
  </button>
</template>

<style scoped lang="scss">
.admin-profile-completion {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px 12px;
  width: 300px; max-width: 100%; border: 0; padding: 5px 0; background: transparent; color: #a33f36; font: inherit; text-align: left; cursor: pointer;
  > span { display: flex; align-items: center; gap: 4px; font-size: 10px; line-height: 1.5; }
  svg { flex-shrink: 0; } b { font-weight: 600; }
  .is-filled { color: #316c55; }
  > .admin-profile-completion__reminders { grid-column: 1 / -1; color: var(--muted-text); font-size: 10px; font-weight: 600; }
  > .admin-profile-completion__strength { grid-column: 1 / -1; display: grid; grid-template-columns: auto auto 1fr; align-items: center; gap: 6px; color: var(--forest); font-size: 11px; font-weight: 600;
    > i { position: relative; height: 4px; overflow: hidden; border-radius: 999px; background: #e5e8e0; }
    > i i { position: absolute; inset: 0 auto 0 0; border-radius: inherit; background: var(--coral-text); }
    &.is-ready > i i { background: #316c55; } }
  &:focus-visible { outline: 2px solid var(--forest); outline-offset: 4px; border-radius: 4px; }
}
</style>
