<script setup>
import { ChartNoAxesColumnIncreasing, Lightbulb, PlayCircle, UserRound, Images, FileText } from '@lucide/vue'
defineProps({ article: { type: Boolean, default: false }, icon: { type: [Object, Function], default: () => UserRound } })
</script>

<template>
  <div v-if="article" class="seo-content__article-art" aria-hidden="true">
    <div class="seo-content__profile-art">
      <span class="seo-content__profile-art-avatar"><component :is="icon" :size="38" /></span>
      <span class="seo-content__profile-art-copy"><i></i><i></i><i></i></span>
      <div class="seo-content__profile-art-tools"><UserRound :size="21" /><Images :size="21" /><PlayCircle :size="21" /><FileText :size="21" /></div>
    </div>
    <span class="seo-content__profile-art-chart"><ChartNoAxesColumnIncreasing :size="36" /></span>
  </div>
  <div v-else class="seo-content__hero-art" aria-hidden="true">
    <div class="seo-content__hero-art-card seo-content__hero-art-card--video">
      <span class="seo-content__video-frame"><PlayCircle :size="22" /></span>
      <span class="seo-content__art-line seo-content__art-line--long"></span>
      <span class="seo-content__art-line"></span>
      <span class="seo-content__art-line seo-content__art-line--short"></span>
    </div>
    <div class="seo-content__hero-art-card seo-content__hero-art-card--brief">
      <span class="seo-content__art-heading"></span>
      <span class="seo-content__art-line seo-content__art-line--long"></span>
      <span class="seo-content__art-line"></span>
      <span class="seo-content__art-line seo-content__art-line--short"></span>
      <span class="seo-content__art-chart">
        <i></i>
        <i></i>
        <i></i>
      </span>
    </div>
    <span class="seo-content__hero-art-bulb"><Lightbulb :size="24" /></span>
    <svg class="seo-content__hero-art-connector" viewBox="0 0 110 100" focusable="false">
      <path d="M3 20c37-8 48 11 47 30-1 14-16 19-22 31-4 8 2 15 10 14 25-3 31-42 68-48" />
      <circle cx="3" cy="20" r="2.5" />
      <circle cx="106" cy="47" r="2.5" />
    </svg>
  </div>
</template>
