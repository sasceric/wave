<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { Check, ChevronDown, Search, X } from '@lucide/vue'

let nextId = 0

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  placeholder: { type: String, required: true },
  searchPlaceholder: { type: String, required: true },
  noResultsLabel: { type: String, required: true },
  removeLabel: { type: String, required: true },
  helperText: { type: String, default: '' },
  maxSelections: { type: Number, default: 0 },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const id = `multi-select-${++nextId}`
const root = ref(null)
const trigger = ref(null)
const searchInput = ref(null)
const isOpen = ref(false)
const query = ref('')

const selectedOptions = computed(() => props.options.filter(
  (option) => props.modelValue.includes(option.value),
))
const normalizedQuery = computed(() => normalize(query.value))
const filteredOptions = computed(() => {
  if (!normalizedQuery.value) {
    return props.options
  }

  return props.options.filter((option) => normalize(option.label).includes(normalizedQuery.value))
})
const isAtLimit = computed(() => (
  props.maxSelections > 0 && props.modelValue.length >= props.maxSelections
))

function normalize(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase()
    .trim()
}

function toggleOption(value) {
  const selected = props.modelValue.includes(value)
  if (!selected && isAtLimit.value) {
    return
  }

  const nextValue = selected
    ? props.modelValue.filter((item) => item !== value)
    : [...props.modelValue, value]
  emit('update:modelValue', nextValue)
}

function removeOption(value) {
  toggleOption(value)
  nextTick(() => {
    if (isOpen.value) {
      searchInput.value?.focus()
    } else {
      trigger.value?.focus()
    }
  })
}

async function openMenu() {
  if (props.disabled || isOpen.value) {
    return
  }

  query.value = ''
  isOpen.value = true
  await nextTick()
  searchInput.value?.focus()
}

function closeMenu(restoreFocus = false) {
  isOpen.value = false
  query.value = ''
  if (restoreFocus) {
    nextTick(() => trigger.value?.focus())
  }
}

function toggleMenu() {
  if (isOpen.value) {
    closeMenu()
    return
  }

  openMenu()
}

function focusOption(index) {
  const optionIndex = Math.min(Math.max(index, 0), filteredOptions.value.length - 1)
  if (optionIndex >= 0) {
    document.getElementById(`${id}-option-${optionIndex}`)?.focus()
  }
}

function focusPreviousOption(index) {
  if (index === 0) {
    searchInput.value?.focus()
    return
  }

  focusOption(index - 1)
}

function closeOnOutsideClick(event) {
  if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
    closeMenu()
  }
}

onMounted(() => document.addEventListener('pointerdown', closeOnOutsideClick))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutsideClick))
</script>

<template>
  <div class="form-field multi-select">
    <span :id="`${id}-label`" class="multi-select__label">{{ label }}</span>
    <small v-if="helperText">{{ helperText }}</small>
    <div
      ref="root"
      class="multi-select__control"
      :class="{ 'is-open': isOpen, 'is-disabled': disabled }"
    >
      <span v-for="option in selectedOptions" :key="option.value" class="multi-select__value">
        <span :title="option.label">{{ option.label }}</span>
        <button
          type="button"
          :aria-label="`${removeLabel}: ${option.label}`"
          :disabled="disabled"
          @click="removeOption(option.value)"
        >
          <X :size="13" aria-hidden="true" />
        </button>
      </span>
      <button
        :id="`${id}-trigger`"
        ref="trigger"
        class="multi-select__trigger"
        type="button"
        :aria-labelledby="`${id}-label`"
        :aria-expanded="isOpen"
        :aria-controls="`${id}-options`"
        aria-haspopup="listbox"
        :disabled="disabled"
        @click="toggleMenu"
        @keydown.esc.prevent="closeMenu(true)"
      >
        <span>{{ selectedOptions.length ? searchPlaceholder : placeholder }}</span>
        <ChevronDown :size="16" aria-hidden="true" />
      </button>
      <div v-if="isOpen" class="multi-select__menu">
        <label class="multi-select__search">
          <Search :size="15" aria-hidden="true" />
          <input
            ref="searchInput"
            v-model="query"
            type="search"
            :aria-label="searchPlaceholder"
            :placeholder="searchPlaceholder"
            @keydown.down.prevent="focusOption(0)"
            @keydown.esc.prevent.stop="closeMenu(true)"
          />
        </label>
        <div
          :id="`${id}-options`"
          class="multi-select__options"
          role="listbox"
          aria-multiselectable="true"
          :aria-labelledby="`${id}-label`"
        >
          <button
            v-for="(option, index) in filteredOptions"
            :id="`${id}-option-${index}`"
            :key="option.value"
            class="multi-select__option"
            :class="{ 'is-selected': modelValue.includes(option.value) }"
            type="button"
            role="option"
            :aria-selected="modelValue.includes(option.value)"
            :disabled="disabled || (!modelValue.includes(option.value) && isAtLimit)"
            @click="toggleOption(option.value)"
            @keydown.down.prevent="focusOption(index + 1)"
            @keydown.up.prevent="focusPreviousOption(index)"
            @keydown.esc.prevent="closeMenu(true)"
          >
            <span>{{ option.label }}</span>
            <span class="multi-select__check" aria-hidden="true">
              <Check v-if="modelValue.includes(option.value)" :size="14" />
            </span>
          </button>
          <p v-if="!filteredOptions.length" class="multi-select__empty">
            {{ noResultsLabel }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
