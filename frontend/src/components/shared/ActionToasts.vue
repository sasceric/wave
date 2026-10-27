<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { CircleAlert, CircleCheck, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { actionToasts } from '../../lib/toasts'
const { t } = useI18n()
const { messages, dismiss, pause, resume } = actionToasts
const host = ref(null)
const target = shallowRef('body')
const revision = computed(() => messages.value.map(item => item.id).join(','))
let observer
let disposed = false
function syncDialog() {
  // Toast controls must remain inside a modal's non-inert subtree.
  target.value = Array.from(document.querySelectorAll('dialog:modal')).at(-1) || 'body'
}
function visibilityChanged() {
  for (const item of messages.value) {
    if (document.hidden) pause(item.id, 'hidden')
    else resume(item.id, 'hidden')
  }
}
function invalidForm() { actionToasts.notify(t('actionFeedback.invalidForm'), 'error') }
onMounted(() => {
  syncDialog()
  observer = new MutationObserver(records => {
    if (records.some(record => record.type === 'attributes' || [...record.addedNodes, ...record.removedNodes].some(node => node.nodeType === 1 && (node.matches('dialog') || node.querySelector('dialog'))))) syncDialog()
  })
  observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'], childList: true })
  document.addEventListener('wave-invalid', invalidForm, true)
  document.addEventListener('visibilitychange', visibilityChanged)
})
watch([revision, target], async () => {
  await nextTick()
  if (disposed || !host.value) return
  // Re-show in the top layer after a modal opens or another action completes.
  if (typeof host.value.showPopover === 'function') {
    if (host.value.matches(':popover-open')) host.value.hidePopover()
    if (messages.value.length) host.value.showPopover()
  }
}, { flush: 'post' })
onBeforeUnmount(() => {
  disposed = true
  observer?.disconnect()
  document.removeEventListener('wave-invalid', invalidForm, true)
  document.removeEventListener('visibilitychange', visibilityChanged)
  host.value?.hidePopover?.()
})
</script>

<template>
  <Teleport :to="target">
    <div ref="host" class="action-toasts" :class="{ 'action-toasts--visible': messages.length }" popover="manual" :aria-label="t('actionFeedback.label')">
      <TransitionGroup name="action-toast" tag="div" class="action-toasts__stack">
        <div v-for="item in messages" :key="item.id" class="action-toasts__item" :class="`action-toasts__item--${item.variant}`" @pointerenter="pause(item.id, 'pointer')" @pointerleave="resume(item.id, 'pointer')" @focusin="pause(item.id, 'focus')" @focusout="resume(item.id, 'focus')">
          <component :is="item.variant === 'error' ? CircleAlert : CircleCheck" class="action-toasts__icon" :size="22" aria-hidden="true" />
          <p :role="item.variant === 'error' ? 'alert' : 'status'" aria-atomic="true">{{ item.message }}</p>
          <button type="button" class="action-toasts__close" :aria-label="t('actionFeedback.dismiss')" @click="dismiss(item.id)"><X :size="18" aria-hidden="true" /></button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style lang="scss" src="../../scss/components/shared/ActionToasts.scss"></style>
