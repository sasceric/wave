<script setup>
defineProps({
  shape: { type: String, default: 'line' },
  width: { type: String, default: '100%' },
  size: { type: String, default: '48px' },
})
</script>

<template>
  <span class="skeleton-block" :class="`skeleton-block--${shape}`" :style="{ width: shape === 'avatar' ? size : width, height: shape === 'avatar' ? size : undefined }" aria-hidden="true"></span>
</template>

<style scoped lang="scss" src="../../scss/components/shared/SkeletonBlock.scss"></style>
