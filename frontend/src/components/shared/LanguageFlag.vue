<script setup>
defineProps({
  locale: { type: String, required: true },
})

const flags = {
  bs: '🇧🇦',
  hr: '🇭🇷',
  sr: '🇷🇸',
  sl: '🇸🇮',
  en: '🇬🇧',
  cnr: '🇲🇪',
}
</script>

<template>
  <span class="language-flag" aria-hidden="true">{{ flags[locale] || flags.en }}</span>
</template>
