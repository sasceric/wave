<script setup>
defineProps({
  modelValue: { type: String, required: true },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  block: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <div class="segmented-control" :class="{ 'segmented-control--block': block }" role="group" :aria-label="label">
    <button
      v-for="option in options"
      :key="option.value"
      class="segmented-control__option"
      type="button"
      :aria-pressed="modelValue === option.value"
      @click="emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped lang="scss" src="../../scss/components/shared/SegmentedControl.scss"></style>
