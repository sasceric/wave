<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
const props = defineProps({ modelValue: { type: Boolean, default: false }, id: { type: String, required: true }, title: { type: String, required: true }, clearLabel: { type: String, required: true }, applyLabel: { type: String, required: true } })
const emit = defineEmits(['update:modelValue', 'clear'])
const { t } = useI18n()
const filterPanel = ref(null)
const mobile = ref(false)
let previousFocus = null
let previousOverflow = ''
let disposed = false
function closeFilters() { emit('update:modelValue', false) }
function updateViewport() {
  mobile.value = window.matchMedia('(max-width: 760px)').matches
  if (!mobile.value && props.modelValue) closeFilters()
}
watch(() => props.modelValue, async (open) => {
  if (open) {
    previousFocus = document.activeElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    await nextTick()
    if (!disposed && props.modelValue) filterPanel.value?.focus({ preventScroll: true })
  } else {
    document.body.style.overflow = previousOverflow
    previousFocus?.focus({ preventScroll: true })
  }
})
function trapFocus(event) {
  if (!props.modelValue) return
  if (event.key === 'Escape') { event.preventDefault(); closeFilters(); return }
  if (event.key !== 'Tab') return
  const elements = [...filterPanel.value.querySelectorAll('button, input, select, [tabindex="0"]')].filter((item) => item.getClientRects().length && !item.disabled)
  const first = elements[0]
  const last = elements.at(-1)
  if (event.shiftKey && (document.activeElement === first || document.activeElement === filterPanel.value)) {
    event.preventDefault(); last?.focus()
  } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === filterPanel.value)) {
    event.preventDefault(); first?.focus()
  }
}
onMounted(() => { updateViewport(); window.addEventListener('resize', updateViewport) })
onBeforeUnmount(() => { disposed = true; window.removeEventListener('resize', updateViewport); if (props.modelValue) document.body.style.overflow = previousOverflow })
</script>

<template>
  <div v-if="modelValue" class="directory-filter-panel__backdrop" aria-hidden="true" @click="closeFilters"></div>
  <aside :id="id" ref="filterPanel" class="directory-filter-panel" :class="{ 'is-open': modelValue }" :role="mobile ? 'dialog' : undefined" :aria-modal="mobile && modelValue ? true : undefined" :aria-hidden="mobile && !modelValue ? true : undefined" :aria-labelledby="`${id}-title`" tabindex="-1" @keydown="trapFocus">
    <div class="directory-filter-panel__handle" aria-hidden="true"></div>
    <button class="directory-filter-panel__close" type="button" :aria-label="t('app.closeMenu')" @click="closeFilters"><X :size="24" aria-hidden="true" /></button>
    <div class="directory-filter-panel__head">
      <h2 :id="`${id}-title`">{{ title }}</h2>
      <button class="directory-filter-panel__clear" type="button" @click="emit('clear')">{{ clearLabel }}</button>
    </div>
    <slot />
    <div class="directory-filter-panel__mobile"><slot name="mobile" /></div>
    <button class="directory-filter-panel__apply" type="button" @click="closeFilters">{{ applyLabel }}</button>
  </aside>
</template>

<style lang="scss" src="../../scss/components/shared/DirectoryFilterPanel.scss"></style>
