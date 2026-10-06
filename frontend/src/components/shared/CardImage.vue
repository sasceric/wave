<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import WaveLogo from './WaveLogo.vue'
import { listingImage } from '../../lib/listingImage'

const props = defineProps({
  image: { type: Object, default: null },
  src: { type: String, default: '' },
  alt: { type: String, default: '' },
  sizes: { type: String, default: '(max-width: 760px) calc((100vw - 60px) / 2), 560px' },
})
const element = ref(null)
const state = ref('loading')
const source = computed(() => listingImage(props.image, props.src))

function checkCachedImage() {
  if (element.value?.complete && element.value.naturalWidth > 0) state.value = 'loaded'
}

function handleError() {
  state.value = 'error'
}

watch(() => [source.value?.src, source.value?.srcset], async () => {
  state.value = 'loading'
  await nextTick()
  checkCachedImage()
})
onMounted(checkCachedImage)
</script>

<template>
  <span class="card-image" :class="`card-image--${state}`">
    <img
      v-if="source && state !== 'error'"
      ref="element"
      :src="source.src"
      :srcset="source.srcset || undefined"
      :sizes="source.srcset ? sizes : undefined"
      :width="source.width || undefined"
      :height="source.height || undefined"
      :alt="alt"
      loading="lazy"
      decoding="async"
      @load="checkCachedImage"
      @error="handleError"
    />
    <span v-else class="card-image__fallback" role="img" :aria-label="alt || undefined" :aria-hidden="alt ? undefined : true">
      <WaveLogo mark aria-hidden="true" />
    </span>
  </span>
</template>

<style lang="scss" src="../../scss/components/shared/CardImage.scss"></style>
