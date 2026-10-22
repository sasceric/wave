<script setup>
import { computed } from 'vue'
const props = defineProps({ html: { type: String, default: '' }, title: { type: String, required: true }, allowBrandImages: { type: Boolean, default: false } })
// No scripts, forms, network requests or parent-page access from captured email HTML.
const source = computed(() => {
  const origins = props.allowBrandImages ? [window.location?.origin, 'https://wave.ba'].filter(Boolean) : []
  const images = origins.flatMap(origin => ['icon', 'logo'].map(name => `${origin}/images/email/wave-${name}-v1.png`))
  return `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:${images.length ? ` ${images.join(' ')}` : ''}; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">${props.html}`
})
</script>
<template><iframe :srcdoc="source" sandbox="" referrerpolicy="no-referrer" :title="title"></iframe></template>
