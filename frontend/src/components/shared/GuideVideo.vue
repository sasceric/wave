<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ExternalLink, Play } from '@lucide/vue'

defineProps({ video: { type: Object, required: true } })
const { t } = useI18n()
const playing = ref(false)
</script>

<template>
  <article class="guide-video">
    <div class="guide-video__player">
      <iframe
        v-if="playing"
        :src="`https://www.youtube-nocookie.com/embed/${video.id}?autoplay=1&playsinline=1&rel=0`"
        :title="t(`regionalSeo.videoTutorials.${video.topic}`)"
        allow="autoplay; encrypted-media; picture-in-picture; fullscreen; web-share"
        allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
      ></iframe>
      <button
        v-else
        type="button"
        class="guide-video__preview"
        :aria-label="t('regionalSeo.videoTutorials.play', { title: t(`regionalSeo.videoTutorials.${video.topic}`) })"
        @click="playing = true"
      >
        <img :src="video.poster" alt="" loading="lazy" width="1280" height="720">
        <span class="guide-video__play" aria-hidden="true"><Play :size="28" /></span>
      </button>
    </div>
    <div class="guide-video__body">
      <h3>{{ t(`regionalSeo.videoTutorials.${video.topic}`) }}</h3>
      <p>{{ t('regionalSeo.videoTutorials.language') }}</p>
      <a :href="`https://www.youtube.com/watch?v=${video.id}`" target="_blank" rel="noopener noreferrer">
        {{ t('regionalSeo.videoTutorials.watch') }}<ExternalLink :size="15" aria-hidden="true" />
      </a>
    </div>
  </article>
</template>

<style scoped lang="scss" src="../../scss/components/shared/GuideVideo.scss"></style>
