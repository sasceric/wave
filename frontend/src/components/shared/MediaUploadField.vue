<script setup>
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiUpload } from '../../lib/api'

const props = defineProps({
  folder: { type: String, required: true },
  modelValue: { type: Number, default: null },
  previewUrl: { type: String, default: '' },
  optional: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'uploaded'])

const inputId = useId()
const { t } = useI18n()
const busy = ref(false)
const error = ref('')
const required = computed(() => !props.optional && !props.modelValue && !props.previewUrl)

async function upload(event) {
  const input = event.target
  const file = input.files?.[0]
  if (!file) return

  busy.value = true
  error.value = ''
  try {
    const response = await apiUpload(`/media?folder=${encodeURIComponent(props.folder)}`, file)
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
  <div class="media-upload-field" :class="{ 'has-preview': previewUrl }">
    <img
      v-if="previewUrl"
      class="media-upload-field__preview"
      :src="previewUrl"
      alt=""
      loading="lazy"
    />
    <label class="form-field" :for="inputId">
      <span>{{ busy ? t('account.mediaUploading') : t('account.mediaUpload') }}</span>
      <input
        :id="inputId"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        :required="required"
        :disabled="busy"
        @change="upload"
      />
    </label>
    <small>{{ t('account.mediaUploadHint') }}</small>
    <p v-if="error" class="media-upload-field__error" role="alert">{{ error }}</p>
  </div>
</template>
