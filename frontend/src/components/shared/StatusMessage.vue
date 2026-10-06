<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'info',
    validator: (value) => ['info', 'error', 'empty'].includes(value),
  },
  role: { type: String, default: null },
})

const className = computed(() => {
  if (props.variant === 'empty') {
    return 'empty-state'
  }

  return props.variant === 'error' ? 'notice notice--error' : 'notice'
})

const ariaRole = computed(() => {
  if (props.role) {
    return props.role
  }
  if (props.variant === 'error') {
    return 'alert'
  }
  if (props.variant === 'info') {
    return 'status'
  }

  return undefined
})
</script>

<template>
  <p :class="className" :role="ariaRole">
    <slot />
  </p>
</template>

<style lang="scss" src="./StatusMessage.scss"></style>
