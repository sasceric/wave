<script setup>
import { nextTick, ref, watch } from 'vue'
const props = defineProps({
  items: { type: Array, required: true },
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  sections: { type: Boolean, default: false },
  idPrefix: { type: String, default: 'profile' },
})
const emit = defineEmits(['update:modelValue'])
const root = ref(null)
function choose(item, event) {
  emit('update:modelValue', item.key)
  const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
  if (props.sections) document.getElementById(item.key)?.scrollIntoView({ behavior, block: 'start' })
  const link = event?.currentTarget
  if (root.value && link) root.value.scrollTo({ left: root.value.scrollLeft + link.getBoundingClientRect().left - root.value.getBoundingClientRect().left - 12, behavior })
}
function keydown(event) {
  const controls = Array.from(root.value?.querySelectorAll('a, button') || [])
  const index = controls.indexOf(document.activeElement)
  if (index < 0) return
  let next = index
  if (event.key === 'ArrowRight') next = (index + 1) % controls.length
  else if (event.key === 'ArrowLeft') next = (index - 1 + controls.length) % controls.length
  else if (event.key === 'Home') next = 0
  else if (event.key === 'End') next = controls.length - 1
  else return
  event.preventDefault()
  controls[next].focus()
  if (!props.sections) choose(props.items[next], { currentTarget: controls[next] })
}
function scrollTabs(event) {
  const tabs = event.currentTarget
  if (tabs.scrollWidth <= tabs.clientWidth || Math.abs(event.deltaX) >= Math.abs(event.deltaY)) return
  const next = Math.max(0, Math.min(tabs.scrollWidth - tabs.clientWidth, tabs.scrollLeft + event.deltaY))
  if (next === tabs.scrollLeft) return
  event.preventDefault()
  tabs.scrollLeft = next
}
watch(() => props.modelValue, async () => {
  if (props.sections) return
  await nextTick()
  root.value?.querySelector('[aria-selected="true"], [aria-current="location"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
})
</script>
<template>
  <nav ref="root" :class="sections ? 'profile-section-nav' : 'profile-tabs'" :role="sections ? undefined : 'tablist'" :aria-label="label" @keydown="keydown" @wheel="scrollTabs">
    <template v-for="item in items" :key="item.key">
      <a v-if="sections" :href="`#${item.key}`" :aria-current="modelValue === item.key ? 'location' : undefined" @click.prevent="choose(item, $event)">{{ item.label }}<span v-if="item.count !== undefined">{{ item.count }}</span></a>
      <button v-else :id="`${idPrefix}-tab-${item.key}`" type="button" role="tab" :aria-selected="modelValue === item.key" :tabindex="modelValue === item.key ? 0 : -1" @click="choose(item, $event)">{{ item.label }}<span v-if="item.count !== undefined">{{ item.count }}</span></button>
    </template>
  </nav>
</template>
<style lang="scss" src="../../scss/components/shared/ProfileTabs.scss"></style>
