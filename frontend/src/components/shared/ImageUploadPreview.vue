<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Eye } from '@lucide/vue'
import { coverPreviewFrames } from '../../lib/imageUpload'

const props = defineProps({
  src: { type: String, required: true },
  folder: { type: String, required: true },
  companyType: { type: String, default: 'brand' },
})
const { t } = useI18n()
const frames = computed(() => coverPreviewFrames(props.folder, props.companyType))
</script>

<template>
  <details v-if="frames.length" class="image-upload-preview">
    <summary><Eye :size="16" aria-hidden="true" />{{ t('imageUpload.preview') }}</summary>
    <p>{{ t('imageUpload.previewHint') }}</p>
    <div class="image-upload-preview__grid">
      <figure v-for="frame in frames" :key="frame.key">
        <figcaption>{{ t(`imageUpload.${frame.key}`) }}</figcaption>
        <div :style="{ aspectRatio: frame.ratio }">
          <img :src="src" alt="" :style="{ objectPosition: frame.position || 'center' }" loading="lazy" decoding="async" />
        </div>
      </figure>
    </div>
  </details>
</template>

<style lang="scss" src="../../scss/components/shared/ImageUploadPreview.scss"></style>
