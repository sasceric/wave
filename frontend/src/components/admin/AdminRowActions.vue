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
  nextTick(() => {
    const height = menu.value?.getBoundingClientRect().height || estimatedMenuHeight
    menuStyle.value = {
      left: `${left}px`,
      top: `${rect.bottom + height + 8 <= window.innerHeight ? rect.bottom + 4 : Math.max(8, rect.top - height - 4)}px`,
    }
    menuItems()[0]?.focus()
  })
}

function menuItems() {
  return Array.from(menu.value?.querySelectorAll('[role="menuitem"]:not(:disabled)') || [])
}

function handleDocumentClick(event) {
  if (root.value?.contains(event.target) || menu.value?.contains(event.target)) return
  closeMenu()
}

function handleKeydown(event) {
  if (!open.value) return
  if (event.key === 'Escape') {
    event.preventDefault()
    closeMenu(true)
    return
  }
  if (!menu.value?.contains(event.target)) return
  const items = menuItems()
  const index = items.indexOf(document.activeElement)
  let next
  if (event.key === 'ArrowDown') next = (index + 1) % items.length
  else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = items.length - 1
  else if (event.key === 'Tab') { closeMenu(); return }
  else return
  event.preventDefault()
  items[next]?.focus()
}

function handleViewportChange(event) {
  if (menu.value?.contains(event?.target)) return
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
      @keydown.down.prevent="!open && toggleMenu()"
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
        @click="closeMenu(true)"
      >
        <slot />
      </div>
    </Teleport>
  </div>
</template>

<style lang="scss" src="../../scss/components/admin/AdminRowActions.scss"></style>
