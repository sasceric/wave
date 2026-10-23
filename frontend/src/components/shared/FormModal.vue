<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  open: { type: Boolean, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  busy: { type: Boolean, default: false },
  wide: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'submit'])
const { t } = useI18n()
const dialog = ref(null)
const id = useId()
let overflow = null
let disposed = false
function restore() {
  if (overflow !== null) document.body.style.overflow = overflow
  overflow = null
}
function close() {
  if (!props.busy) emit('update:open', false)
}
watch(() => props.open, async (open) => {
  await nextTick()
  if (disposed || !dialog.value) return
  if (open && !dialog.value.open) {
    overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    dialog.value.showModal()
  } else if (!open) {
    dialog.value.close()
    restore()
  }
}, { immediate: true })
onBeforeUnmount(() => { disposed = true; dialog.value?.close(); restore() })
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" class="account-form-modal wave-modal wave-modal--scroll-body" :class="{ 'wave-modal--wide': wide }" aria-modal="true" :aria-labelledby="`${id}-title`" :aria-describedby="description ? `${id}-description` : undefined" @cancel.prevent="close" @click.self="close" @close="restore(); emit('update:open', false)">
      <form v-form-validation :aria-busy="busy" @submit.prevent="emit('submit')">
        <header>
          <h2 :id="`${id}-title`" class="wave-modal__title">{{ title }}</h2>
          <button class="wave-modal__close" type="button" :disabled="busy" :aria-label="t('reviews.close')" @click="close"><X :size="20" aria-hidden="true" /></button>
        </header>
        <p v-if="description" :id="`${id}-description`" class="account-form-modal__intro">{{ description }}</p>
        <div class="account-form-modal__fields"><slot /></div>
        <footer><slot name="actions" :close="close" /></footer>
      </form>
    </dialog>
  </Teleport>
</template>

<style scoped lang="scss" src="../../scss/components/shared/FormModal.scss"></style>
