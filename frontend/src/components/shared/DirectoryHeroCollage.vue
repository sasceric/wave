<script setup>
defineProps({
  mainImage: { type: String, required: true },
  secondaryImage: { type: String, required: true },
  detailImage: { type: String, required: true },
})
</script>

<template>
  <div class="directory-hero-collage" aria-hidden="true">
    <img class="directory-hero-collage__main" :src="mainImage" alt="" width="640" height="640" fetchpriority="high" />
    <img class="directory-hero-collage__secondary" :src="secondaryImage" alt="" width="240" height="240" />
    <img class="directory-hero-collage__detail" :src="detailImage" alt="" width="120" height="120" />
    <span class="directory-hero-collage__badge"><slot /></span>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/DirectoryHeroCollage.scss"></style>
