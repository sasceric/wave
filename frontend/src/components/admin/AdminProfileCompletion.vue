<script setup>
import { Check, Circle } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
defineProps({ row: { type: Object, required: true } })
defineEmits(['review'])
const { t } = useI18n()
</script>

<template>
  <button class="admin-profile-completion" type="button" :aria-label="`${t('adminProfiles.review')}: ${row.displayName || row.name || row.email}`" @click="$emit('review')">
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
  &:focus-visible { outline: 2px solid var(--forest); outline-offset: 4px; border-radius: 4px; }
}
</style>
