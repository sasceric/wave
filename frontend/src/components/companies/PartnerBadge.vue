<script setup>
import { useI18n } from 'vue-i18n'

// "Partner Wave.ba" seal for brands marked by administrators. The tilt lives inside the SVG; CSS transforms or filters would rasterize it and blur.
defineProps({ size: { type: Number, default: 72 } })
const { t } = useI18n()
</script>

<template>
  <span class="partner-badge" :title="t('companyDirectory.partnerHint')">
    <img src="/images/partner-badge.svg" :alt="t('companyDirectory.partner')" :width="size" :height="Math.round(size * 236 / 220)" loading="lazy" decoding="async" />
  </span>
</template>

<style scoped>
.partner-badge {
  display: inline-block;
  line-height: 0;
}

.partner-badge img { display: block; height: auto; }
</style>
