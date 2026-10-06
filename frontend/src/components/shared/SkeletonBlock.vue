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

<style scoped>
.skeleton-block { position: relative; display: block; flex-shrink: 0; height: 13px; overflow: hidden; border-radius: 5px; background: #dce3da; isolation: isolate; }
.skeleton-block::after { position: absolute; inset: 0; content: ''; background: linear-gradient(100deg, transparent 20%, rgba(255, 255, 255, .4) 50%, transparent 80%); transform: translateX(-100%); animation: wave-skeleton 1.5s ease-in-out infinite; }
.skeleton-block--title { height: 32px; }
.skeleton-block--avatar { border-radius: 50%; }
.skeleton-block--control { height: 44px; border-radius: 9px; }
.skeleton-block--media { height: auto; aspect-ratio: 1; border-radius: 12px; }
@media (prefers-reduced-motion: reduce) { .skeleton-block::after { display: none; animation: none; } }
</style>
