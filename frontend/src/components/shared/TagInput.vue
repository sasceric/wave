<script setup>
import { computed, nextTick, ref, useId } from 'vue'
import { X } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  label: { type: String, required: true },
  placeholder: { type: String, required: true },
  removeLabel: { type: String, required: true },
  limitLabel: { type: String, default: '' },
  tooLongLabel: { type: String, default: '' },
  maxTags: { type: Number, default: 0 },
  maxLength: { type: Number, default: 0 },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const id = `tag-input-${useId()}`
const input = ref(null)
const draft = ref('')
const error = ref('')
const composing = ref(false)
const atLimit = computed(() => props.maxTags > 0 && props.modelValue.length >= props.maxTags)

function commitTag() {
  if (props.disabled || composing.value) return
  const tag = draft.value.trim()
  if (!tag) {
    draft.value = ''
    clearError()
    return
  }
  if (props.modelValue.some((value) => value.normalize('NFC').toLowerCase() === tag.normalize('NFC').toLowerCase())) {
    draft.value = ''
    clearError()
    return
  }
  if (atLimit.value || (props.maxLength > 0 && Array.from(tag).length > props.maxLength)) {
    error.value = atLimit.value ? props.limitLabel : props.tooLongLabel
    input.value?.setCustomValidity(error.value)
    return
  }
  emit('update:modelValue', [...props.modelValue, tag])
  draft.value = ''
  clearError()
}

function clearError() {
  error.value = ''
  input.value?.setCustomValidity('')
}

function handleKeydown(event) {
  // Enter confirms IME text first; it must not create a partial tag.
  if (event.key !== 'Enter' || event.isComposing || composing.value || event.keyCode === 229) return
  event.preventDefault()
  commitTag()
}

function removeTag(index) {
  if (props.disabled) return
  emit('update:modelValue', props.modelValue.filter((_, position) => position !== index))
  clearError()
  nextTick(() => input.value?.focus({ preventScroll: true }))
}
</script>

<template>
  <div class="form-field tag-input">
    <label :for="id">{{ label }}</label>
    <div class="tag-input__control" :class="{ 'is-disabled': disabled }">
      <span v-for="(tag, index) in modelValue" :key="index" class="tag-input__tag">
        <span>{{ tag }}</span>
        <button
          type="button"
          :disabled="disabled"
          :aria-label="`${removeLabel}: ${tag}`"
          @click="removeTag(index)"
        ><X :size="13" aria-hidden="true" /></button>
      </span>
      <input
        :id="id"
        ref="input"
        v-model="draft"
        class="tag-input__input"
        type="text"
        :disabled="disabled"
        :readonly="atLimit && !draft"
        :maxlength="maxLength > 0 ? maxLength : undefined"
        :placeholder="atLimit ? limitLabel : placeholder"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="error ? `${id}-error` : undefined"
        autocomplete="off"
        enterkeyhint="enter"
        @input="clearError"
        @keydown="handleKeydown"
        @blur="commitTag"
        @compositionstart="composing = true"
        @compositionend="composing = false"
      />
    </div>
    <small v-if="error" :id="`${id}-error`" class="tag-input__error" role="alert">{{ error }}</small>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/TagInput.scss"></style>
