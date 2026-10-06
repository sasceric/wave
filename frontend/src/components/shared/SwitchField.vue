<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Number,
    default: 0,
  },
  label: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue'])
const enabled = computed(() => props.modelValue === 1)

function toggle() {
  if (!props.disabled) {
    emit('update:modelValue', enabled.value ? 0 : 1)
  }
}
</script>

<template>
  <div class="switch-field">
    <span class="switch-field__copy">
      <span class="switch-field__label">{{ label }}</span>
      <span v-if="description" class="switch-field__description">{{ description }}</span>
    </span>
    <button
      class="switch-field__control"
      type="button"
      role="switch"
      :aria-checked="enabled"
      :aria-label="label"
      :disabled="disabled"
      @click="toggle"
    >
      <span class="switch-field__thumb" aria-hidden="true"></span>
    </button>
  </div>
</template>

<style lang="scss" src="./SwitchField.scss"></style>
