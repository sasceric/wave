<script setup>
import { nextTick, ref, useId, watch } from 'vue'
import { AlertTriangle } from '@lucide/vue'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, required: true },
  message: { type: String, required: true },
  confirmLabel: { type: String, required: true },
  cancelLabel: { type: String, required: true },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'update:open'])
const dialog = ref(null)
const id = useId()

watch(() => props.open, async (open) => {
  await nextTick()
  if (!dialog.value) return

  if (open && !dialog.value.open) dialog.value.showModal()
  else if (!open && dialog.value.open) dialog.value.close()
}, { flush: 'post' })

function dismiss() {
  if (!props.loading) emit('update:open', false)
}
</script>

<template>
  <dialog
    ref="dialog"
    class="confirmation-modal"
    aria-modal="true"
    :aria-labelledby="`${id}-title`"
    :aria-describedby="`${id}-message`"
    @cancel.prevent="dismiss"
    @close="emit('update:open', false)"
    @click.self="dismiss"
  >
    <section class="confirmation-modal__content" :aria-busy="loading">
      <span class="confirmation-modal__icon"><AlertTriangle :size="19" aria-hidden="true" /></span>
      <h2 :id="`${id}-title`">{{ title }}</h2>
      <p :id="`${id}-message`">{{ message }}</p>
      <footer class="confirmation-modal__actions">
        <button class="button button--outline" type="button" :disabled="loading" @click="dismiss">
          {{ cancelLabel }}
        </button>
        <button class="button button--dark confirmation-modal__confirm" type="button" :disabled="loading" @click="emit('confirm')">
          {{ loading ? `${confirmLabel}…` : confirmLabel }}
        </button>
      </footer>
    </section>
  </dialog>
</template>
