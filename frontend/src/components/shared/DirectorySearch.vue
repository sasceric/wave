<script setup>
import { Search } from '@lucide/vue'
defineProps({ modelValue: { type: String, default: '' }, label: { type: String, required: true }, placeholder: { type: String, default: '' }, icon: { type: Boolean, default: true } })
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <label class="directory-search">
    <Search v-if="icon" :size="20" aria-hidden="true" />
    <input :value="modelValue" type="search" :placeholder="placeholder" :aria-label="label" @input="emit('update:modelValue', $event.target.value)" />
  </label>
</template>

<style lang="scss" src="../../scss/components/shared/DirectorySearch.scss"></style>
