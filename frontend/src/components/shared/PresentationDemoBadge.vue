<script setup>
import { useI18n } from 'vue-i18n'
defineProps({ compact: Boolean })
const { t } = useI18n()
</script>
<template>
  <span class="presentation-demo-badge" :class="{ 'presentation-demo-badge--compact': compact }">{{ t('presentationDemo.badge') }}</span>
</template>
<style scoped lang="scss">
.presentation-demo-badge {
  display: inline-flex; align-items: center; width: fit-content; max-width: 100%; padding: 7px 14px; border-radius: 30px; color: #a4553f; background: #f6e5dd; font-size: 13px; font-weight: 400; line-height: 1.5; white-space: normal;
  &--compact { padding: 4px 9px; font-size: 11px; }
}
</style>
