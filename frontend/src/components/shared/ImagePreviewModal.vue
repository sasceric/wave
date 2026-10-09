<script setup>
import { nextTick, ref, useId, watch } from 'vue'
import { Download, X } from '@lucide/vue'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, default: '' },
  src: { type: String, default: '' },
  downloadUrl: { type: String, default: '' },
  closeLabel: { type: String, required: true },
  downloadLabel: { type: String, required: true },
})
const emit = defineEmits(['update:open'])
const dialog = ref(null)
const id = useId()

watch(() => props.open, async (open) => {
  await nextTick()
  if (!dialog.value) return
  if (open && !dialog.value.open) dialog.value.showModal()
  else if (!open && dialog.value.open) dialog.value.close()
}, { flush: 'post' })
</script>

<template>
  <dialog ref="dialog" class="image-preview-modal" aria-modal="true" :aria-labelledby="`${id}-title`" @cancel.prevent="emit('update:open', false)" @close="emit('update:open', false)" @click.self="emit('update:open', false)">
    <section>
      <header>
        <h2 :id="`${id}-title`">{{ title }}</h2>
        <button type="button" :aria-label="closeLabel" @click="emit('update:open', false)"><X :size="20" aria-hidden="true" /></button>
      </header>
      <div class="image-preview-modal__image"><img v-if="open && src" :src="src" :alt="title" /></div>
      <footer><a class="button button--outline" :href="downloadUrl" download><Download :size="16" aria-hidden="true" />{{ downloadLabel }}</a></footer>
    </section>
  </dialog>
</template>

<style lang="scss" src="../../scss/components/shared/ImagePreviewModal.scss"></style>
