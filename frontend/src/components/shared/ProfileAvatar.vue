<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  src: { type: String, default: '' },
  alt: { type: String, default: '' },
  score: { type: Number, default: null },
})
const { t } = useI18n()
const failedSource = ref('')
const progress = computed(() => Number.isFinite(props.score) ? Math.max(0, Math.min(100, props.score)) : null)
const accessibleLabel = computed(() => [props.alt, progress.value === null ? '' : t('profileStrength.scoreLabel', { score: progress.value })].filter(Boolean).join('. '))
</script>

<template>
  <span class="profile-avatar" :role="accessibleLabel ? 'img' : undefined" :aria-label="accessibleLabel || undefined">
    <svg v-if="progress !== null" class="profile-avatar__ring" viewBox="0 0 44 44" aria-hidden="true">
      <circle class="profile-avatar__track" cx="22" cy="22" r="20" />
      <circle class="profile-avatar__progress" cx="22" cy="22" r="20" pathLength="100" stroke-dasharray="100" :stroke-dashoffset="100 - progress" transform="rotate(-90 22 22)" />
    </svg>
    <span class="profile-avatar__image" aria-hidden="true">
      <img v-if="src && failedSource !== src" :src="src" alt="" @error="failedSource = src" />
      <slot v-else />
    </span>
  </span>
</template>

<style lang="scss" src="../../scss/components/shared/ProfileAvatar.scss"></style>
