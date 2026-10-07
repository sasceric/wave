<script setup>
import { nextTick, ref, useId, watch } from 'vue'
import { Check } from '@lucide/vue'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, required: true },
  message: { type: String, required: true },
  closeLabel: { type: String, required: true },
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

function close() {
  emit('update:open', false)
}
</script>

<template>
  <dialog
    ref="dialog"
    class="success-modal"
    aria-modal="true"
    :aria-labelledby="`${id}-title`"
    @cancel.prevent="close"
    @close="emit('update:open', false)"
    @click.self="close"
  >
    <section class="success-modal__content">
      <span class="success-modal__icon"><Check :size="20" aria-hidden="true" /></span>
      <h2 :id="`${id}-title`">{{ title }}</h2>
      <p>{{ message }}</p>
      <footer class="success-modal__actions">
        <button class="button button--dark" type="button" @click="close">{{ closeLabel }}</button>
      </footer>
    </section>
  </dialog>
</template>

<style lang="scss" src="../../scss/components/shared/SuccessModal.scss"></style>
