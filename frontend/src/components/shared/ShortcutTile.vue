<script setup>
import LocalizedLink from './LocalizedLink.vue'

defineProps({
  to: { type: [String, Object], required: true },
  icon: { type: [Object, Function], required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  badge: { type: [Number, String], default: 0 },
  badgeLabel: { type: String, default: '' },
})
</script>

<template>
  <LocalizedLink class="shortcut-tile" :to="to">
    <span class="shortcut-tile__top">
      <span class="shortcut-tile__icon" aria-hidden="true"><component :is="icon" :size="20" /></span>
      <span v-if="badge" class="shortcut-tile__badge" :aria-label="badgeLabel || undefined">{{ Number(badge) > 99 ? '99+' : badge }}</span>
    </span>
    <strong>{{ title }}</strong>
    <span v-if="description" class="shortcut-tile__description">{{ description }}</span>
  </LocalizedLink>
</template>

<style scoped lang="scss" src="../../scss/components/shared/ShortcutTile.scss"></style>
