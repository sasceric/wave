<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { Check, ChevronDown } from '@lucide/vue'
import { getDropdownPlacement } from '../../utils/dropdownPlacement'

const props = defineProps({
  modelValue: { type: [String, Number, Boolean], default: '' },
  options: { type: Array, required: true },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  showLabel: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const id = `single-select-${useId()}`
const root = ref(null)
const trigger = ref(null)
const menu = ref(null)
const isOpen = ref(false)
const opensAbove = ref(false)
const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue))

function updateMenuPlacement() {
  if (!isOpen.value || !root.value || !menu.value) return
  menu.value.style.removeProperty('--dropdown-available-height')
  menu.value.style.removeProperty('--dropdown-inline-offset')
  const placement = getDropdownPlacement(trigger.value, menu.value)
  if (!placement) return
  opensAbove.value = placement.opensAbove
  menu.value.style.setProperty('--dropdown-available-height', `${placement.maxHeight}px`)
  const bounds = menu.value.getBoundingClientRect()
  const viewport = window.visualViewport
  const left = (viewport?.offsetLeft ?? 0) + 8
  const right = (viewport?.offsetLeft ?? 0) + (viewport?.width ?? window.innerWidth) - 8
  const offset = bounds.left < left ? left - bounds.left : bounds.right > right ? right - bounds.right : 0
  menu.value.style.setProperty('--dropdown-inline-offset', `${offset}px`)
}
function focusOption(index, step = 1) {
  const options = props.options
  let target = Math.min(Math.max(index, 0), options.length - 1)
  while (target >= 0 && target < options.length && options[target].disabled) target += step
  if (target < 0 || target >= options.length) return
  const element = document.getElementById(`${id}-option-${target}`)
  element?.focus({ preventScroll: true })
  if (element && menu.value) {
    const top = element.offsetTop
    const bottom = top + element.offsetHeight
    if (top < menu.value.scrollTop) menu.value.scrollTop = top
    else if (bottom > menu.value.scrollTop + menu.value.clientHeight) menu.value.scrollTop = bottom - menu.value.clientHeight
  }
}
async function openMenu(last = false) {
  if (props.disabled) return
  isOpen.value = true
  await nextTick()
  updateMenuPlacement()
  const index = props.options.findIndex((option) => option.value === props.modelValue && !option.disabled)
  focusOption(index >= 0 ? index : last ? props.options.length - 1 : 0, last ? -1 : 1)
}
function closeMenu(restoreFocus = false) {
  isOpen.value = false
  opensAbove.value = false
  if (restoreFocus) nextTick(() => trigger.value?.focus({ preventScroll: true }))
}
function selectOption(option) {
  if (props.disabled || option.disabled) return
  emit('update:modelValue', option.value)
  closeMenu(true)
}
function moveOption(index, direction) {
  let target = index + direction
  while (target >= 0 && target < props.options.length && props.options[target].disabled) target += direction
  if (target >= 0 && target < props.options.length) focusOption(target)
}
function closeOnOutsideClick(event) {
  if (root.value && event.target instanceof Node && !root.value.contains(event.target)) closeMenu()
}
function closeOnFocusOut(event) {
  if (!root.value?.contains(event.relatedTarget)) closeMenu()
}
function handleEscape(event) {
  if (!isOpen.value) return
  event.preventDefault()
  event.stopPropagation()
  closeMenu(true)
}
onMounted(() => {
  document.addEventListener('pointerdown', closeOnOutsideClick)
  window.addEventListener('resize', updateMenuPlacement)
  window.addEventListener('scroll', updateMenuPlacement, true)
  window.visualViewport?.addEventListener('resize', updateMenuPlacement)
  window.visualViewport?.addEventListener('scroll', updateMenuPlacement)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOnOutsideClick)
  window.removeEventListener('resize', updateMenuPlacement)
  window.removeEventListener('scroll', updateMenuPlacement, true)
  window.visualViewport?.removeEventListener('resize', updateMenuPlacement)
  window.visualViewport?.removeEventListener('scroll', updateMenuPlacement)
})
</script>

<template>
  <div ref="root" class="form-field single-select" :class="{ 'is-above': opensAbove }" @focusout="closeOnFocusOut" @keydown.esc="handleEscape">
    <span :id="`${id}-label`" class="single-select__label" :class="{ 'single-select__label--hidden': !showLabel }">{{ label }}</span>
    <button
      ref="trigger"
      class="single-select__trigger"
      :class="{ 'is-open': isOpen }"
      type="button"
      :aria-labelledby="selectedOption?.label === label ? `${id}-label` : `${id}-label ${id}-value`"
      :aria-expanded="isOpen"
      :aria-controls="`${id}-options`"
      aria-haspopup="listbox"
      :disabled="disabled"
      @click="isOpen ? closeMenu() : openMenu()"
      @keydown.down.prevent="openMenu()"
      @keydown.up.prevent="openMenu(true)"
    >
      <span :id="`${id}-value`">{{ selectedOption?.label || placeholder || label }}</span>
      <ChevronDown :size="16" aria-hidden="true" />
    </button>
    <div v-if="isOpen" :id="`${id}-options`" ref="menu" class="single-select__menu" role="listbox" :aria-labelledby="`${id}-label`">
      <button
        v-for="(option, index) in options"
        :id="`${id}-option-${index}`"
        :key="option.value"
        class="single-select__option"
        :class="{ 'is-selected': modelValue === option.value }"
        type="button"
        role="option"
        tabindex="-1"
        :aria-selected="modelValue === option.value"
        :disabled="option.disabled"
        @click="selectOption(option)"
        @keydown.down.prevent="moveOption(index, 1)"
        @keydown.up.prevent="moveOption(index, -1)"
        @keydown.home.prevent="focusOption(0)"
        @keydown.end.prevent="focusOption(options.length - 1, -1)"
      >
        <span>{{ option.label }}</span>
        <Check v-if="modelValue === option.value" :size="16" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/SingleSelect.scss"></style>
