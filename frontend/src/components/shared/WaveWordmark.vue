<script setup>
import LocalizedLink from './LocalizedLink.vue'
import WaveLogo from './WaveLogo.vue'

defineProps({
  light: { type: Boolean, default: false },
  ariaLabel: { type: String, required: true },
})
</script>

<template>
  <LocalizedLink
    class="wordmark"
    :class="{ 'wordmark--light': light }"
    to="/"
    :aria-label="ariaLabel"
  >
    <WaveLogo class="wordmark__image" />
  </LocalizedLink>
</template>
