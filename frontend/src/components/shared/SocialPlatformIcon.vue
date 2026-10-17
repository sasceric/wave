<script setup>
import { computed } from 'vue'
import { Globe } from '@lucide/vue'
import instagram from '../../../../public/images/instagram.svg?url'
import tiktok from '../../../../public/images/tiktok.svg?url'
import youtube from '../../../../public/images/youtube.svg?url'
import facebook from '../../../../public/images/facebook.svg?url'
import linkedin from '../../../../public/images/linkedin.svg?url'
const props = defineProps({ platform: { type: String, required: true }, size: { type: Number, default: 20 } })
const icons = { Instagram: instagram, TikTok: tiktok, YouTube: youtube, Facebook: facebook, LinkedIn: linkedin }
const iconStyle = computed(() => ({ '--social-icon': `url(${JSON.stringify(icons[props.platform])})`, width: `${props.size}px`, height: `${props.size}px` }))
</script>
<template>
  <span v-if="icons[platform]" class="social-platform-icon" :style="iconStyle" aria-hidden="true"></span>
  <Globe v-else :size="size" aria-hidden="true" />
</template>
<style lang="scss" src="../../scss/components/shared/SocialPlatformIcon.scss"></style>
