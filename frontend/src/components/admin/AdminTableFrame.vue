<script setup>
defineProps({ busy: { type: Boolean, default: false } })
</script>

<template>
  <div class="admin-table" :aria-busy="busy">
    <div class="admin-table__header">
      <slot name="toolbar" />
    </div>
    <slot />
  </div>
</template>

<style lang="scss" src="../../scss/components/admin/AdminTableFrame.scss"></style>
