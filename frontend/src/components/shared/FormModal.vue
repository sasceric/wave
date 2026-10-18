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
    <dialog ref="dialog" class="account-form-modal" :class="{ 'account-form-modal--wide': wide }" :aria-labelledby="`${id}-title`" :aria-describedby="description ? `${id}-description` : undefined" @cancel.prevent="close" @click.self="close" @close="restore(); emit('update:open', false)">
      <form v-form-validation :aria-busy="busy" @submit.prevent="emit('submit')">
        <header>
          <h2 :id="`${id}-title`">{{ title }}</h2>
          <button type="button" :disabled="busy" :aria-label="t('reviews.close')" @click="close"><X :size="20" aria-hidden="true" /></button>
        </header>
        <p v-if="description" :id="`${id}-description`" class="account-form-modal__intro">{{ description }}</p>
        <div class="account-form-modal__fields"><slot /></div>
        <footer><slot name="actions" :close="close" /></footer>
      </form>
    </dialog>
  </Teleport>
</template>

<style scoped lang="scss">
.account-form-modal {
  width: min(500px, calc(100vw - 32px)); max-height: calc(100dvh - 40px); padding: 0;
  border: 1px solid #e2e6df; border-radius: 16px; color: var(--ink); background: #fffefa;
  box-shadow: 0 20px 60px #173d3626;
  &--wide { width: min(600px, calc(100vw - 32px)); }
  &::backdrop { background: #173d3666; }
  form { padding: 24px; }
  header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  h2 { margin: 0; font-size: 24px; color: var(--forest); }
  header button { display: grid; place-items: center; flex: 0 0 36px; height: 36px; padding: 0; border: 1px solid #dce2d9; border-radius: 50%; background: #fffefa; color: var(--forest); cursor: pointer; }
  &__intro { font-size: 12px; color: var(--muted); line-height: 1.6; margin: 10px 0 20px; }
  &__fields { display: grid; gap: 16px; margin-top: 18px; }
  &__fields :deep(label) { display: grid; gap: 6px; font-size: 12px; }
  &__fields :deep(.form-field > label) { display: block; }
  &__fields :deep(input:not(.dp--input)), &__fields :deep(textarea) { box-sizing: border-box; width: 100%; border: 1px solid #dce2d9; border-radius: 8px; padding: 12px; background: white; color: var(--ink); font: inherit; font-size: 14px; }
  &__fields :deep(textarea) { min-height: 120px; resize: vertical; }
  footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
  footer :deep(.button) { min-height: 40px; font-size: 12px; padding: 10px 16px; border-radius: 8px; gap: 8px; }
}
</style>
