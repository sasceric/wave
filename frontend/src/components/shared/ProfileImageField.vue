<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiRequest, apiUpload } from '../../lib/api'
import ConfirmationModal from './ConfirmationModal.vue'
import ImageUploadControl from './ImageUploadControl.vue'

const props = defineProps({
  compact: { type: Boolean, default: false },
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
const { t } = useI18n()
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
  if (props.disabled || hasPreparedChange.value || preview.value) return
  fileInput.value?.click()
}

function selectFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file || props.disabled || hasPreparedChange.value || preview.value) return

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

  try {
    await apiRequest(`/media/${change.uploadedMediaId}`, { method: 'DELETE' })
  } catch (cause) {
    // A retry may find an upload that has already been cleaned up.
    if (cause.status !== 404) throw cause
  }
  preparedChange = null
  hasPreparedChange.value = false
}

onBeforeUnmount(revokePendingPreview)

defineExpose({ prepareSave, commit, rollback })
</script>

<template>
  <div class="profile-image-upload">
    <ImageUploadControl
      :compact="compact"
      :preview-url="preview"
      :alt="alt"
      :add-label="addLabel"
      :change-label="changeLabel"
      :remove-label="removeLabel"
      :helper-text="helperText"
      :disabled="disabled || hasPreparedChange"
      :allow-replace="false"
      can-remove
      @choose="openFilePicker"
      @remove="removalConfirmationOpen = true"
    />
    <p v-if="preview" class="profile-image-upload__hint">{{ t('account.profileImageReplaceHint') }}</p>
    <p v-else-if="pendingRemoval" class="profile-image-upload__hint" role="status">{{ t('account.profileImageRemovalPending') }}</p>
    <input
      :id="inputId"
      ref="fileInput"
      class="profile-image-field__input"
      type="file"
      accept="image/jpeg,image/png,image/webp"
      :disabled="disabled || hasPreparedChange || Boolean(preview)"
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

<style lang="scss" src="../../scss/components/shared/ProfileImageField.scss"></style>
