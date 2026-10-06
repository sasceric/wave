<script setup>
import { computed } from 'vue'
import { sanitizeRichText } from '../../lib/richText'

const props = defineProps({ html: { type: String, default: '' } })
const safeHtml = computed(() => sanitizeRichText(props.html))
</script>

<template>
  <div class="profile-bio" v-html="safeHtml"></div>
</template>

<style lang="scss" src="./RichTextContent.scss"></style>
