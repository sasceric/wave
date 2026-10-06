<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { ImagePlus, Upload, X } from '@lucide/vue'
import { apiRequest, apiUpload } from '../../lib/api'
import ConfirmationModal from './ConfirmationModal.vue'

const props = defineProps({
  folder: { type: String, required: true },
  modelValue: { type: Number, default: null },
  previewUrl: { type: String, default: '' },
  alt: { type: String, required: true },
  addLabel: { type: String, required: true },
  changeLabel: { type: String, required: true },
  removeLabel: { type: String, required: true },
  helperText: { type: String, required: true },
  removeTitle: { type: String, required: true },
  removeMessage: { type: String, required: true },
  confirmLabel: { type: String, required: true },
  cancelLabel: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})

const inputId = useId()
const fileInput = ref(null)
const pendingFile = ref(null)
const pendingPreview = ref('')
const pendingRemoval = ref(false)
const hasPreparedChange = ref(false)
const removalConfirmationOpen = ref(false)
let preparedChange = null

const preview = computed(() => {
  if (pendingRemoval.value) return ''

  return pendingPreview.value || props.previewUrl
})

function revokePendingPreview() {
  if (pendingPreview.value) {
    URL.revokeObjectURL(pendingPreview.value)
    pendingPreview.value = ''
  }
}

function openFilePicker() {
  fileInput.value?.click()
}

function selectFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  revokePendingPreview()
  pendingFile.value = file
  pendingRemoval.value = false
  preparedChange = null
  hasPreparedChange.value = false
  pendingPreview.value = URL.createObjectURL(file)
}

function stageRemoval() {
  revokePendingPreview()
  pendingFile.value = null
  pendingRemoval.value = true
  preparedChange = null
  hasPreparedChange.value = false
  removalConfirmationOpen.value = false
}

async function prepareSave() {
  if (preparedChange) return preparedChange
  if (!pendingFile.value && !pendingRemoval.value) return null

  const previousMediaId = props.modelValue || null
  if (pendingRemoval.value) {
    preparedChange = {
      mediaId: null,
      url: null,
      previousMediaId,
      uploadedMediaId: null,
    }
    hasPreparedChange.value = true

    return preparedChange
  }

  const response = await apiUpload(
    `/media?folder=${encodeURIComponent(props.folder)}`,
    pendingFile.value,
  )
  preparedChange = {
    mediaId: response.data.id,
    url: response.data.url,
    previousMediaId,
    uploadedMediaId: response.data.id,
  }
  hasPreparedChange.value = true

  return preparedChange
}

function commit() {
  revokePendingPreview()
  pendingFile.value = null
  pendingRemoval.value = false
  preparedChange = null
  hasPreparedChange.value = false
}

async function rollback(change) {
  if (!change?.uploadedMediaId) return

  await apiRequest(`/media/${change.uploadedMediaId}`, { method: 'DELETE' })
  preparedChange = null
  hasPreparedChange.value = false
}

onBeforeUnmount(revokePendingPreview)

defineExpose({ prepareSave, commit, rollback })
</script>

<template>
  <div class="profile-image-field" :aria-busy="disabled">
    <button
      v-if="!preview"
      class="profile-image-field__empty"
      type="button"
      :disabled="disabled || hasPreparedChange"
      @click="openFilePicker"
    >
      <ImagePlus :size="25" aria-hidden="true" />
      <span>{{ addLabel }}</span>
      <small>{{ helperText }}</small>
    </button>
    <div v-else class="profile-image-field__image">
      <img :src="preview" :alt="alt" />
      <button
        class="profile-image-field__remove"
        type="button"
        :aria-label="removeLabel"
        :title="removeLabel"
        :disabled="disabled || hasPreparedChange"
        @click="removalConfirmationOpen = true"
      >
        <X :size="17" aria-hidden="true" />
      </button>
      <button
        class="profile-image-field__change"
        type="button"
        :disabled="disabled || hasPreparedChange"
        @click="openFilePicker"
      >
        <Upload :size="14" aria-hidden="true" />
        {{ changeLabel }}
      </button>
    </div>
    <input
      :id="inputId"
      ref="fileInput"
      class="profile-image-field__input"
      type="file"
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled || hasPreparedChange"
      @change="selectFile"
    />
    <ConfirmationModal
      v-model:open="removalConfirmationOpen"
      :title="removeTitle"
      :message="removeMessage"
      :confirm-label="confirmLabel"
      :cancel-label="cancelLabel"
      @confirm="stageRemoval"
    />
  </div>
</template>

<style lang="scss" src="./ProfileImageField.scss"></style>
