<script setup>
import { computed } from 'vue'
const props = defineProps({ html: { type: String, default: '' }, title: { type: String, required: true }, allowBrandImages: { type: Boolean, default: false } })
// No scripts, forms, network requests or parent-page access from captured email HTML.
const source = computed(() => {
  const origins = props.allowBrandImages ? [window.location?.origin, 'https://wave.ba'].filter(Boolean) : []
  const images = origins.flatMap(origin => ['icon', 'logo'].map(name => `${origin}/images/email/wave-${name}-v1.png`))
  // Preview only Wave's two bundled brand images, using this app's asset origin.
  // The API origin can differ from Vite's origin during development.
  const html = props.allowBrandImages ? props.html.replace(/(<img\b[^>]*?\bsrc\s*=\s*)(["'])(.*?)\2/gi, (match, prefix, quote, src) => {
    try {
      const url = new URL(src, window.location.origin)
      if (['http:', 'https:'].includes(url.protocol) && /^\/images\/email\/wave-(icon|logo)-v1\.png$/.test(url.pathname)) {
        return `${prefix}${quote}${window.location.origin}${url.pathname}${quote}`
      }
    } catch { /* Unrecognized images remain blocked by CSP. */ }
    return match
  }) : props.html
  return `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:${images.length ? ` ${images.join(' ')}` : ''}; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">${html}`
})
</script>
<template><iframe :srcdoc="source" sandbox="" referrerpolicy="no-referrer" :title="title"></iframe></template>
