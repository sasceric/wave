<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { MoreHorizontal } from '@lucide/vue'

defineProps({
  label: { type: String, required: true },
})

const root = ref(null)
const trigger = ref(null)
const menu = ref(null)
const open = ref(false)
const menuStyle = ref({})
const menuWidth = 184
const estimatedMenuHeight = 120

function closeMenu(restoreFocus = false) {
  open.value = false
  if (restoreFocus) trigger.value?.focus()
}

function toggleMenu() {
  if (open.value) {
    closeMenu()
    return
  }

  const rect = trigger.value.getBoundingClientRect()
  const top = rect.bottom + estimatedMenuHeight <= window.innerHeight
    ? rect.bottom + 4
    : Math.max(8, rect.top - estimatedMenuHeight - 4)
  const left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8))
  menuStyle.value = { top: `${top}px`, left: `${left}px` }
  open.value = true
  nextTick(() => menu.value?.querySelector('[role="menuitem"]')?.focus())
}

function handleDocumentClick(event) {
  if (root.value?.contains(event.target) || menu.value?.contains(event.target)) return
  closeMenu()
}

function handleKeydown(event) {
  if (event.key === 'Escape' && open.value) closeMenu(true)
}

function handleViewportChange() {
  if (open.value) closeMenu()
}

onMounted(() => {
  document.addEventListener('click', handleDocumentClick)
  document.addEventListener('keydown', handleKeydown)
  window.addEventListener('resize', handleViewportChange)
  window.addEventListener('scroll', handleViewportChange, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick)
  document.removeEventListener('keydown', handleKeydown)
  window.removeEventListener('resize', handleViewportChange)
  window.removeEventListener('scroll', handleViewportChange, true)
})
</script>

<template>
  <div ref="root" class="admin-row-actions">
    <button
      ref="trigger"
      class="admin-row-actions__trigger"
      type="button"
      :aria-label="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="toggleMenu"
    >
      <MoreHorizontal :size="18" aria-hidden="true" />
    </button>
    <Teleport to="body">
      <div
        v-if="open"
        ref="menu"
        class="admin-row-actions__menu"
        role="menu"
        :aria-label="label"
        :style="menuStyle"
        @click="closeMenu"
      >
        <slot />
      </div>
    </Teleport>
  </div>
</template>

<style lang="scss" src="../../scss/components/admin/AdminRowActions.scss"></style>
