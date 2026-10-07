<script setup>
import { Camera, ImagePlus, Upload, X } from '@lucide/vue'

defineProps({
  compact: { type: Boolean, default: false },
  previewUrl: { type: String, default: '' },
  alt: { type: String, default: '' },
  addLabel: { type: String, required: true },
  changeLabel: { type: String, required: true },
  removeLabel: { type: String, default: '' },
  helperText: { type: String, default: '' },
  canRemove: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})
defineEmits(['choose', 'remove'])
</script>

<template>
  <div class="profile-image-field" :class="{ 'profile-image-field--avatar': compact }" :aria-busy="disabled">
    <button v-if="!previewUrl" class="profile-image-field__empty" data-validation-control type="button" :disabled="disabled" @click="$emit('choose')">
      <ImagePlus :size="25" aria-hidden="true" />
      <span>{{ addLabel }}</span>
      <small v-if="helperText">{{ helperText }}</small>
    </button>
    <div v-else class="profile-image-field__image">
      <img :src="previewUrl" :alt="alt" />
      <button v-if="canRemove && !compact" class="profile-image-field__remove" type="button" :aria-label="removeLabel" :title="removeLabel" :disabled="disabled" @click="$emit('remove')">
        <X :size="17" aria-hidden="true" />
      </button>
      <button class="profile-image-field__change" data-validation-control type="button" :disabled="disabled" @click="$emit('choose')">
        <Camera v-if="compact" :size="20" aria-hidden="true" />
        <Upload v-else :size="14" aria-hidden="true" />
        <span :class="{ 'sr-only': compact }">{{ changeLabel }}</span>
      </button>
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/ImageUploadControl.scss"></style>
