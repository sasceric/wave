<script setup>
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Copy, Share2 } from '@lucide/vue'
import FormModal from './FormModal.vue'
import StatusMessage from './StatusMessage.vue'
import { localizedPath } from '../../routePaths'

const props = defineProps({
  routeName: { type: String, required: true },
  slug: { type: String, required: true },
  title: { type: String, required: true },
})
const { t, locale } = useI18n()
const id = useId()
const open = ref(false)
const busy = ref(false)
const copied = ref(false)
const copyError = ref(false)
const url = computed(() => new URL(localizedPath(props.routeName, locale.value, { slug: props.slug }), window.location.origin).href)

watch(url, () => { open.value = false; copied.value = false; copyError.value = false })

async function share() {
  if (busy.value) return
  copied.value = false
  copyError.value = false
  if (typeof navigator.share !== 'function') {
    open.value = true
    return
  }
  busy.value = true
  const currentUrl = url.value
  try {
    // Call directly from the tap so Safari retains the required user activation.
    await navigator.share({ title: props.title, url: currentUrl })
  } catch (error) {
    if (currentUrl === url.value && error?.name !== 'AbortError') open.value = true
  } finally {
    busy.value = false
  }
}

async function copy() {
  if (busy.value) return
  busy.value = true
  copied.value = false
  copyError.value = false
  const currentUrl = url.value
  try {
    await navigator.clipboard.writeText(currentUrl)
    if (currentUrl === url.value) copied.value = true
  } catch {
    if (currentUrl === url.value) copyError.value = true
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="public-share">
    <button class="public-share__button" type="button" :disabled="busy" :aria-label="t('sharing.shareTitle', { title })" :title="t('sharing.share')" @click="share">
      <Share2 :size="17" aria-hidden="true" />
    </button>
    <FormModal v-model:open="open" :title="t('sharing.share')" :description="title">
      <label :for="`${id}-url`">{{ t('sharing.link') }}
        <input :id="`${id}-url`" :value="url" readonly type="url" @focus="$event.target.select()" />
      </label>
      <StatusMessage v-if="copied">{{ t('sharing.copied') }}</StatusMessage>
      <StatusMessage v-if="copyError" variant="error">{{ t('sharing.copyManually') }}</StatusMessage>
      <template #actions>
        <button class="button button--dark" type="button" :disabled="busy" @click="copy"><Copy :size="16" aria-hidden="true" />{{ t('sharing.copyLink') }}</button>
      </template>
    </FormModal>
  </div>
</template>

<style scoped lang="scss">
// Round icon button from the profile prototype; the label lives in aria-label and the tooltip.
.public-share {
  display: inline-flex;
  flex: 0 0 auto;

  &__button {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    color: var(--ink);
    background: rgba(31, 42, 40, .06);
    cursor: pointer;
    transition: background .2s var(--ease-standard);

    &:hover { background: rgba(31, 42, 40, .1); }
    &:focus-visible { outline: 2px solid var(--forest); outline-offset: 2px; }
    &:disabled { cursor: progress; opacity: .6; }
  }

  @media (max-width: 760px) {
    &__button { width: 44px; height: 44px; }
  }
}
</style>
