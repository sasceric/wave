<script setup>
import { Camera, ImagePlus, Upload, X } from '@lucide/vue'
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { imageRecommendationKey } from '../../lib/imageUpload'
import ImageUploadPreview from './ImageUploadPreview.vue'

const props = defineProps({
  folder: { type: String, default: '' },
  companyType: { type: String, default: 'brand' },
  compact: { type: Boolean, default: false },
  previewUrl: { type: String, default: '' },
  alt: { type: String, default: '' },
  addLabel: { type: String, required: true },
  changeLabel: { type: String, required: true },
  removeLabel: { type: String, default: '' },
  helperText: { type: String, default: '' },
  canRemove: { type: Boolean, default: false },
  allowReplace: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
})
const { t } = useI18n()
const hintId = useId()
const recommendation = computed(() => imageRecommendationKey(props.folder))
defineEmits(['choose', 'remove'])
</script>

<template>
  <div class="profile-image-field" :class="{ 'profile-image-field--avatar': compact, 'profile-image-field--cover': folder === 'company-cover', 'profile-image-field--landscape': ['campaign-cover', 'creator-portfolio'].includes(folder), 'profile-image-field--logo': folder === 'company-logo' }" :aria-busy="disabled">
    <button v-if="!previewUrl" class="profile-image-field__empty" data-validation-control type="button" :aria-describedby="helperText || recommendation ? hintId : undefined" :disabled="disabled" @click="$emit('choose')">
      <ImagePlus :size="25" aria-hidden="true" />
      <span>{{ addLabel }}</span>
    </button>
    <div v-else class="profile-image-field__image">
      <img :src="previewUrl" :alt="alt" />
      <button v-if="canRemove && (!compact || !allowReplace)" class="profile-image-field__remove" type="button" :aria-label="removeLabel" :title="removeLabel" :disabled="disabled" @click="$emit('remove')">
        <X :size="17" aria-hidden="true" />
      </button>
      <button v-if="allowReplace" class="profile-image-field__change" data-validation-control type="button" :aria-describedby="helperText || recommendation ? hintId : undefined" :disabled="disabled" @click="$emit('choose')">
        <Camera v-if="compact" :size="20" aria-hidden="true" />
        <Upload v-else :size="14" aria-hidden="true" />
        <span :class="{ 'sr-only': compact }">{{ changeLabel }}</span>
      </button>
    </div>
    <div v-if="helperText || recommendation" :id="hintId" class="profile-image-field__hint">
      <p v-if="recommendation" class="profile-image-field__recommendation">{{ t(recommendation) }}</p>
      <p v-if="helperText">{{ helperText }}</p>
    </div>
    <ImageUploadPreview v-if="previewUrl && ['company-cover', 'campaign-cover'].includes(folder)" :src="previewUrl" :folder="folder" :company-type="companyType" />
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/ImageUploadControl.scss"></style>
