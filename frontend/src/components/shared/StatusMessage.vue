<script setup>
import { computed } from 'vue'
import { CircleAlert, CircleCheck } from '@lucide/vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'info',
    validator: (value) => ['info', 'success', 'error', 'empty'].includes(value),
  },
  // Optional bold first line; the slot becomes the explanation under it.
  title: { type: String, default: '' },
  role: { type: String, default: null },
})

const className = computed(() => {
  if (props.variant === 'empty') return 'empty-state'
  if (props.variant === 'error') return 'notice notice--error'
  if (props.variant === 'success') return 'notice notice--success'

  return 'notice'
})

const ariaRole = computed(() => {
  if (props.role) return props.role
  if (props.variant === 'error') return 'alert'
  if (props.variant === 'info' || props.variant === 'success') return 'status'

  return undefined
})
const icon = computed(() => ({ success: CircleCheck, error: CircleAlert })[props.variant] || null)
</script>

<template>
  <div v-if="icon || title" :class="[className, 'notice--rich']" :role="ariaRole">
    <component :is="icon" v-if="icon" class="notice__icon" :size="20" aria-hidden="true" />
    <div class="notice__body">
      <strong v-if="title" class="notice__title">{{ title }}</strong>
      <span class="notice__text"><slot /></span>
    </div>
  </div>
  <p v-else :class="className" :role="ariaRole">
    <slot />
  </p>
</template>

<style lang="scss" src="../../scss/components/shared/StatusMessage.scss"></style>
