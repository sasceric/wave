<script setup>
import ActionMenu from '../shared/ActionMenu.vue'
defineProps({ label: { type: String, required: true } })
</script>

<template>
  <ActionMenu :label="label"><slot /></ActionMenu>
</template>

<style lang="scss" src="../../scss/components/admin/AdminRowActions.scss"></style>
