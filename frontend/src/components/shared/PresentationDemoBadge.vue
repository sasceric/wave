<script setup>
import { useI18n } from 'vue-i18n'
// overlay: short glass label on a card image; pill: short label beside a profile breadcrumb.
// Both keep the full wording in the title.
defineProps({ compact: Boolean, overlay: Boolean, pill: Boolean })
const { t } = useI18n()
</script>
<template>
  <span
    class="presentation-demo-badge"
    :class="{ 'presentation-demo-badge--compact': compact, 'presentation-demo-badge--overlay': overlay, 'presentation-demo-badge--pill': pill }"
    :title="overlay || pill ? t('presentationDemo.badge') : undefined"
  >{{ overlay || pill ? t('presentationDemo.shortBadge') : t('presentationDemo.badge') }}</span>
</template>
<style scoped lang="scss">
.presentation-demo-badge {
  display: inline-flex; align-items: center; width: fit-content; max-width: 100%; padding: 7px 14px; border-radius: 30px; color: #a4553f; background: #f6e5dd; font-size: 13px; font-weight: 400; line-height: 1.5; white-space: normal;
  &--compact { padding: 4px 9px; font-size: 12px; }
  &--overlay {
    padding: 4px 10px; border: 1px solid rgba(255, 255, 255, .6); color: #8f4734; background: rgba(250, 232, 224, .86);
    -webkit-backdrop-filter: blur(16px) saturate(180%); backdrop-filter: blur(16px) saturate(180%);
    font-size: 12px; font-weight: 600; line-height: 1.3; white-space: nowrap;
  }
  &--pill { padding: 4px 10px; color: var(--coral-text); background: rgba(217, 131, 104, .12); font-size: 12px; font-weight: 600; line-height: 1.4; white-space: nowrap; }
}
</style>
