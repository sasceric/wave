<script setup>
defineProps({
  mark: { type: Boolean, default: false },
})
</script>

<template>
  <img
    v-if="mark"
    class="wave-logo wave-logo--mark"
    src="/images/logo-icon.svg"
    alt=""
    aria-hidden="true"
  />
  <svg
    v-else
    class="wave-logo wave-logo--wordmark"
    viewBox="48 55 660 225"
    aria-hidden="true"
  >
    <text
      x="70"
      y="255"
      fill="#173d36"
      font-family="'DM Sans', sans-serif"
      font-size="230"
      font-weight="700"
      letter-spacing="-1.7px"
    >wave</text>
    <circle cx="684" cy="238" r="18" fill="#d98368" />
  </svg>
</template>

<style lang="scss" src="./WaveLogo.scss"></style>
