<script setup>
import { computed } from 'vue'

const props = defineProps({
  kind: { type: String, required: true, validator: (value) => ['creator', 'campaign', 'company'].includes(value) },
  layout: { type: String, default: 'directory', validator: (value) => ['directory', 'home', 'bookmarks'].includes(value) },
})

const classes = computed(() => {
  if (props.kind === 'company') return 'company-directory__grid'
  const base = `${props.kind}-grid`
  if (props.layout === 'bookmarks') return `${base} account-bookmarks-grid`
  if (props.layout === 'home') return props.kind === 'creator' ? base : `${base} ${base}--home`
  return `${base} ${base}--directory`
})
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>

<style lang="scss" src="./CardGrid.scss"></style>
