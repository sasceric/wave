<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { SlidersHorizontal } from '@lucide/vue'
import DirectorySearch from './DirectorySearch.vue'

const props = defineProps({
  search: { type: String, default: '' },
  searchLabel: { type: String, required: true },
  searchPlaceholder: { type: String, required: true },
  filtersLabel: { type: String, required: true },
  filtersId: { type: String, required: true },
  filtersOpen: { type: Boolean, default: false },
  activeFilterCount: { type: Number, default: 0 },
  controlColumns: { type: Number, default: 3 },
})
const emit = defineEmits(['update:search', 'update:filtersOpen'])
const toolbar = ref(null)
const toolbarAnchor = ref(null)
const toolbarSticky = ref(false)
let stickyFrame = null
function updateStickyState() {
  if (!toolbar.value || !toolbarAnchor.value) return
  const offset = Number.parseFloat(window.getComputedStyle(toolbar.value).top) || 0
  toolbarSticky.value = toolbarAnchor.value.getBoundingClientRect().top <= offset
}
function scheduleStickyUpdate() {
  if (stickyFrame !== null) return
  stickyFrame = window.requestAnimationFrame(() => {
    stickyFrame = null
    updateStickyState()
  })
}
onMounted(() => {
  window.addEventListener('resize', scheduleStickyUpdate)
  window.addEventListener('scroll', scheduleStickyUpdate, { passive: true })
  scheduleStickyUpdate()
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', scheduleStickyUpdate)
  window.removeEventListener('scroll', scheduleStickyUpdate)
  if (stickyFrame !== null) window.cancelAnimationFrame(stickyFrame)
})
</script>

<template>
  <div ref="toolbarAnchor" class="directory-toolbar-anchor" aria-hidden="true"></div>
  <section ref="toolbar" class="directory-toolbar" :class="{ 'is-sticky': toolbarSticky }" :aria-label="filtersLabel">
    <DirectorySearch :model-value="props.search" :label="searchLabel" :placeholder="searchPlaceholder" @update:model-value="emit('update:search', $event)" />
    <button class="directory-toolbar__filter-trigger" type="button" :aria-label="filtersLabel" :aria-expanded="filtersOpen" :aria-controls="filtersId" @click="emit('update:filtersOpen', true)">
      <SlidersHorizontal :size="21" aria-hidden="true" /><strong v-if="activeFilterCount">{{ activeFilterCount }}</strong>
    </button>
    <div class="directory-toolbar__controls" :style="{ '--control-columns': controlColumns }"><slot /></div>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/DirectoryToolbar.scss"></style>
