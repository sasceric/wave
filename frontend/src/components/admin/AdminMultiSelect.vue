<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Check, ChevronDown, Search, X } from '@lucide/vue'
import { getDropdownPlacement } from '../../utils/dropdownPlacement'

const props = defineProps({
  label: { type: String, required: true },
  options: { type: Array, required: true },
  remote: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  hasMore: { type: Boolean, default: false },
  selectionOptions: { type: Array, default: () => [] },
  modelValue: { type: Array, required: true },
  labels: { type: Object, required: true },
})

const emit = defineEmits(['update:modelValue', 'search', 'load-more'])
const search = ref('')
const dropdown = ref(null)
const trigger = ref(null)
const menu = ref(null)
const opensAbove = ref(false)

const visibleOptions = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  if (props.remote || !query) return props.options

  return props.options.filter((option) => (
    `${option.label} ${option.description ?? ''}`.toLocaleLowerCase().includes(query)
  ))
})

const selectedOptions = computed(() => {
  const selected = new Set(props.modelValue)
  return [...new Map([...props.selectionOptions, ...props.options].map((option) => [option.id, option])).values()].filter(({ id }) => selected.has(id))
})

const visibleIds = computed(() => visibleOptions.value.map(({ id }) => id))
const allVisibleSelected = computed(() => (
  visibleIds.value.length > 0 && visibleIds.value.every((id) => props.modelValue.includes(id))
))

watch(search, (value) => { if (props.remote) emit('search', value) })

function updateMenuPlacement() {
  if (!dropdown.value?.open || !trigger.value || !menu.value) {
    return
  }

  menu.value.style.removeProperty('--dropdown-available-height')
  const placement = getDropdownPlacement(trigger.value, menu.value)
  if (!placement) {
    return
  }

  opensAbove.value = placement.opensAbove
  menu.value.style.setProperty('--dropdown-available-height', `${placement.maxHeight}px`)
}

async function handleDropdownToggle() {
  if (!dropdown.value?.open) {
    opensAbove.value = false
    menu.value?.style.removeProperty('--dropdown-available-height')
    return
  }

  await nextTick()
  updateMenuPlacement()
}

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

onMounted(() => {
  window.addEventListener('resize', updateMenuPlacement)
  window.addEventListener('scroll', updateMenuPlacement, true)
  window.visualViewport?.addEventListener('resize', updateMenuPlacement)
  window.visualViewport?.addEventListener('scroll', updateMenuPlacement)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', updateMenuPlacement)
  window.removeEventListener('scroll', updateMenuPlacement, true)
  window.visualViewport?.removeEventListener('resize', updateMenuPlacement)
  window.visualViewport?.removeEventListener('scroll', updateMenuPlacement)
})
</script>

<template>
  <div class="admin-multi-select">
    <label class="admin-multi-select__label">{{ label }}</label>
    <details
      ref="dropdown"
      class="admin-multi-select__dropdown"
      :class="{ 'is-above': opensAbove }"
      @toggle="handleDropdownToggle"
    >
      <summary ref="trigger" :aria-label="label">
        <span>{{ labels.selectedCount(modelValue.length) }}</span>
        <ChevronDown :size="16" aria-hidden="true" />
      </summary>
      <div ref="menu" class="admin-multi-select__menu">
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
          <p v-if="!loading && visibleOptions.length === 0" class="admin-multi-select__empty">
            {{ labels.noResults }}
          </p>
          <button v-if="hasMore || loading" class="button button--outline" type="button" :disabled="loading" @click="emit('load-more')">
            {{ loading ? labels.loading : labels.loadMore }}
          </button>
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

<style lang="scss" src="../shared/MultiSelect.scss"></style>
<style lang="scss" src="./AdminMultiSelect.scss"></style>
