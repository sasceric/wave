<script setup>
import { computed, ref } from 'vue'
import { Check, ChevronDown, Search, X } from '@lucide/vue'

const props = defineProps({
  label: { type: String, required: true },
  options: { type: Array, required: true },
  modelValue: { type: Array, required: true },
  labels: { type: Object, required: true },
})

const emit = defineEmits(['update:modelValue'])
const search = ref('')

const visibleOptions = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  if (!query) return props.options

  return props.options.filter((option) => (
    `${option.label} ${option.description ?? ''}`.toLocaleLowerCase().includes(query)
  ))
})

const selectedOptions = computed(() => {
  const selected = new Set(props.modelValue)
  return props.options.filter(({ id }) => selected.has(id))
})

const visibleIds = computed(() => visibleOptions.value.map(({ id }) => id))
const allVisibleSelected = computed(() => (
  visibleIds.value.length > 0 && visibleIds.value.every((id) => props.modelValue.includes(id))
))

function toggleOption(id, checked) {
  const selectedIds = new Set(props.modelValue)
  if (checked) selectedIds.add(id)
  else selectedIds.delete(id)
  emit('update:modelValue', [...selectedIds])
}

function toggleVisible() {
  const selectedIds = new Set(props.modelValue)
  if (allVisibleSelected.value) {
    visibleIds.value.forEach((id) => selectedIds.delete(id))
  } else {
    visibleIds.value.forEach((id) => selectedIds.add(id))
  }
  emit('update:modelValue', [...selectedIds])
}

function clearSelection() {
  emit('update:modelValue', [])
}
</script>

<template>
  <div class="admin-multi-select">
    <label class="admin-multi-select__label">{{ label }}</label>
    <details class="admin-multi-select__dropdown">
      <summary :aria-label="label">
        <span>{{ labels.selectedCount(modelValue.length) }}</span>
        <ChevronDown :size="16" aria-hidden="true" />
      </summary>
      <div class="admin-multi-select__menu">
        <label class="admin-multi-select__search">
          <Search :size="15" aria-hidden="true" />
          <input v-model="search" type="search" :aria-label="labels.search" :placeholder="labels.search" />
        </label>
        <div class="admin-multi-select__menu-actions">
          <button type="button" @click="toggleVisible">
            <Check :size="14" aria-hidden="true" />
            {{ allVisibleSelected ? labels.deselectVisible : labels.selectVisible }}
          </button>
          <button v-if="modelValue.length" type="button" @click="clearSelection">
            {{ labels.clear }}
          </button>
        </div>
        <div class="admin-multi-select__options" role="group" :aria-label="label">
          <label
            v-for="option in visibleOptions"
            :key="option.id"
            class="admin-multi-select__option"
          >
            <input
              type="checkbox"
              :checked="modelValue.includes(option.id)"
              @change="toggleOption(option.id, $event.target.checked)"
            />
            <span>
              <strong>{{ option.label }}</strong>
              <small v-if="option.description">{{ option.description }}</small>
            </span>
          </label>
          <p v-if="visibleOptions.length === 0" class="admin-multi-select__empty">
            {{ labels.noResults }}
          </p>
        </div>
      </div>
    </details>
    <div v-if="selectedOptions.length" class="admin-multi-select__selected">
      <span v-for="option in selectedOptions" :key="option.id" class="admin-multi-select__chip">
        {{ option.label }}
        <button
          type="button"
          :aria-label="labels.remove(option.label)"
          @click="toggleOption(option.id, false)"
        >
          <X :size="13" aria-hidden="true" />
        </button>
      </span>
    </div>
    <p v-else class="admin-multi-select__hint">{{ labels.noSelection }}</p>
  </div>
</template>
