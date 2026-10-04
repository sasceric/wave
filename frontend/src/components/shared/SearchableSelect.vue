<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Search } from '@lucide/vue'

let nextId = 0

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  placeholder: { type: String, required: true },
  searchPlaceholder: { type: String, required: true },
  noResultsLabel: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const { locale } = useI18n()
const id = `searchable-select-${++nextId}`
const root = ref(null)
const searchInput = ref(null)
const isOpen = ref(false)
const query = ref('')
const activeIndex = ref(-1)

const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue))
const normalizedQuery = computed(() => normalize(query.value))
const filteredOptions = computed(() => {
  if (!normalizedQuery.value) {
    return props.options
  }

  return props.options.filter((option) => normalize(option.label).includes(normalizedQuery.value))
})
const normalizationLocale = computed(() => {
  if (locale.value === 'cnr') return 'bs'
  if (locale.value === 'sr') return 'sr-Latn'

  return locale.value
})

function normalize(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase(normalizationLocale.value)
    .trim()
}

function setActiveIndex(index) {
  activeIndex.value = index
  if (index >= 0) {
    nextTick(() => {
      document.getElementById(`${id}-option-${index}`)?.scrollIntoView({ block: 'nearest' })
    })
  }
}

async function openMenu() {
  if (props.disabled) {
    return
  }

  if (!isOpen.value) {
    query.value = ''
    isOpen.value = true
    setActiveIndex(Math.max(0, filteredOptions.value.findIndex((option) => option.value === props.modelValue)))
    await nextTick()
    searchInput.value?.focus()
  }
}

function closeMenu(restoreFocus = false) {
  isOpen.value = false
  query.value = ''
  activeIndex.value = -1
  if (restoreFocus) {
    nextTick(() => root.value?.querySelector('button')?.focus())
  }
}

function selectOption(option) {
  emit('update:modelValue', option.value)
  closeMenu(true)
}

function toggleMenu() {
  if (isOpen.value) {
    closeMenu()
    return
  }

  openMenu()
}

function handleInput(event) {
  query.value = event.target.value
  setActiveIndex(filteredOptions.value.length ? 0 : -1)
}

function handleTriggerKeydown(event) {
  if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
    event.preventDefault()
    openMenu()
  } else if (event.key === 'Escape') {
    closeMenu()
  }
}

function handleSearchKeydown(event) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    setActiveIndex(Math.min(activeIndex.value + 1, filteredOptions.value.length - 1))
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    setActiveIndex(activeIndex.value <= 0 ? filteredOptions.value.length - 1 : activeIndex.value - 1)
  } else if (event.key === 'Enter' && activeIndex.value >= 0) {
    event.preventDefault()
    selectOption(filteredOptions.value[activeIndex.value])
  } else if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu(true)
  } else if (event.key === 'Tab') {
    closeMenu()
  }
}

function closeOnOutsideClick(event) {
  if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
    closeMenu()
  }
}

watch(() => props.disabled, (disabled) => {
  if (disabled) {
    closeMenu()
  }
})

onMounted(() => document.addEventListener('pointerdown', closeOnOutsideClick))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutsideClick))
</script>

<template>
  <div class="form-field searchable-select">
    <label class="searchable-select__label" :for="id">{{ label }}</label>
    <div ref="root" class="searchable-select__control">
      <button
        :id="id"
        class="searchable-select__trigger"
        :class="{ 'is-placeholder': !selectedOption }"
        type="button"
        :aria-label="`${label}: ${selectedOption?.label || placeholder}`"
        :aria-expanded="isOpen"
        aria-haspopup="listbox"
        :aria-controls="`${id}-listbox`"
        :disabled="disabled"
        @click="toggleMenu"
        @keydown="handleTriggerKeydown"
      >
        <span>{{ selectedOption?.label || placeholder }}</span>
        <span class="searchable-select__chevron" aria-hidden="true">⌄</span>
      </button>
      <div v-if="isOpen" class="searchable-select__menu">
        <label class="searchable-select__search">
          <Search :size="15" stroke-width="1.8" aria-hidden="true" />
          <input
            ref="searchInput"
            v-model="query"
            type="search"
            role="combobox"
            aria-autocomplete="list"
            :aria-label="searchPlaceholder"
            :placeholder="searchPlaceholder"
            :aria-expanded="isOpen"
            :aria-controls="`${id}-listbox`"
            :aria-activedescendant="activeIndex >= 0 ? `${id}-option-${activeIndex}` : undefined"
            autocomplete="off"
            spellcheck="false"
            @input="handleInput"
            @keydown="handleSearchKeydown"
          />
        </label>
        <div
          :id="`${id}-listbox`"
          class="searchable-select__options"
          role="listbox"
          :aria-label="label"
        >
          <button
            v-for="(option, index) in filteredOptions"
            :id="`${id}-option-${index}`"
            :key="option.value"
            class="searchable-select__option"
            :class="{ 'is-active': activeIndex === index, 'is-selected': option.value === modelValue }"
            type="button"
            role="option"
            :aria-selected="option.value === modelValue"
            @mousedown.prevent
            @mousemove="activeIndex = index"
            @click="selectOption(option)"
          >
            <span>{{ option.label }}</span>
            <span v-if="option.value === modelValue" class="searchable-select__check" aria-hidden="true">✓</span>
          </button>
          <p v-if="!filteredOptions.length" class="searchable-select__empty">{{ noResultsLabel }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
