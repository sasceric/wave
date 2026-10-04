<script setup>
defineProps({
  mark: { type: Boolean, default: false },
})
</script>

<template>
  <svg
    v-if="mark"
    class="wave-logo wave-logo--mark"
    viewBox="0 0 512 512"
    aria-hidden="true"
  >
    <rect width="512" height="512" fill="#173c35" />
    <path
      d="M88 179h75l54 165 55-165h66l55 165 54-165h77L432 365h-76l-52-148-53 148h-76L88 179Z"
      fill="#f7c27c"
    />
    <circle cx="412" cy="117" r="27" fill="#f2a58d" />
  </svg>
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
