<script setup>
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiUpload } from '../../lib/api'
import ImageUploadControl from './ImageUploadControl.vue'

const props = defineProps({
  folder: { type: String, required: true },
  modelValue: { type: Number, default: null },
  previewUrl: { type: String, default: '' },
  optional: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'uploaded'])

const inputId = useId()
const fileInput = ref(null)
const { t } = useI18n()
const busy = ref(false)
const error = ref('')
const uploadedUrl = ref('')
const uploadedId = ref(null)
const preview = computed(() => props.previewUrl || (props.modelValue === uploadedId.value ? uploadedUrl.value : ''))

async function upload(event) {
  const input = event.target
  const file = input.files?.[0]
  if (!file) return

  busy.value = true
  error.value = ''
  try {
    const response = await apiUpload(`/media?folder=${encodeURIComponent(props.folder)}`, file)
    uploadedUrl.value = response.data.url
    uploadedId.value = response.data.id
    emit('update:modelValue', response.data.id)
    emit('uploaded', response.data)
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = false
    input.value = ''
  }
}
</script>

<template>
  <div
    class="media-upload-field"
    data-validation-field
    :data-required="!optional"
    :data-validation-value="modelValue || preview ? 'uploaded' : ''"
    :data-disabled="busy"
  >
    <ImageUploadControl
      :preview-url="preview"
      :add-label="busy ? t('account.mediaUploading') : t('account.mediaUpload')"
      :change-label="t('account.changeProfileImage')"
      :helper-text="t('account.mediaUploadHint')"
      :disabled="busy"
      @choose="fileInput?.click()"
    />
    <input
      :id="inputId"
      ref="fileInput"
      class="profile-image-field__input"
      type="file"
      accept="image/jpeg,image/png,image/webp"
      :disabled="busy"
      @change="upload"
    />
    <p v-if="error" class="media-upload-field__error" role="alert">{{ error }}</p>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/MediaUploadField.scss"></style>
