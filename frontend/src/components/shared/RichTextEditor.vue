<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import { useI18n } from 'vue-i18n'
import { sanitizeRichText } from '../../lib/richText'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  maxlength: { type: Number, default: 1500 },
  minlength: { type: Number, default: 0 },
  required: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { locale, t } = useI18n()
const editor = ref(null)
const editorReady = ref(false)
const currentFormats = ref({})
const lastSelection = ref(null)
const editorContent = computed(() => sanitizeRichText(props.modelValue))
const validationValue = computed(() => {
  const element = document.createElement('div')
  element.innerHTML = editorContent.value
  return element.textContent || ''
})
const toolbar = [
  [{ header: [false, 1, 2, 3] }],
  ['bold', 'italic', 'underline', 'link'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  ['clean'],
]

function getQuill() {
  return editorReady.value ? editor.value?.getQuill() : null
}

function setEditorAccessibility() {
  const quill = getQuill()
  const root = quill?.root
  root?.setAttribute('aria-label', props.label)
  root?.setAttribute('role', 'textbox')
  root?.setAttribute('aria-multiline', 'true')
  root?.setAttribute('aria-required', String(props.required))
  const controls = quill?.getModule?.('toolbar')?.container
  if (!controls) return
  controls.setAttribute('role', 'toolbar')
  controls.setAttribute('aria-label', t('account.richTextToolbar'))
  const labels = {
    '.ql-bold': 'richTextBold', '.ql-italic': 'richTextItalic',
    '.ql-underline': 'richTextUnderline', '.ql-link': 'richTextLink',
    '.ql-list[value="ordered"]': 'richTextNumberedList',
    '.ql-list[value="bullet"]': 'richTextBulletedList', '.ql-clean': 'richTextClear',
  }
  for (const [selector, key] of Object.entries(labels)) {
    const button = controls.querySelector(selector)
    button?.setAttribute('aria-label', t(`account.${key}`))
    button?.setAttribute('title', t(`account.${key}`))
    button?.setAttribute('type', 'button')
  }
  const headingNames = {
    '': t('account.richTextParagraph'), 1: t('account.richTextHeadingOne'),
    2: t('account.richTextHeadingTwo'), 3: t('account.richTextHeadingThree'),
  }
  for (const item of controls.querySelectorAll('.ql-header .ql-picker-label, .ql-header .ql-picker-item')) {
    item.setAttribute('data-label', headingNames[item.getAttribute('data-value') || ''])
  }
  const picker = controls.querySelector('.ql-header .ql-picker-label')
  picker?.setAttribute('aria-label', t('account.richTextHeading'))
  const tooltip = quill.container.querySelector('.ql-tooltip')
  if (tooltip) {
    tooltip.dataset.enterLinkLabel = t('account.richTextLinkPlaceholder')
    const action = tooltip.querySelector('.ql-action')
    action?.setAttribute('data-edit-label', t('account.edit'))
    action?.setAttribute('data-save-label', t('account.richTextApplyLink'))
    tooltip.querySelector('.ql-remove')?.setAttribute('data-remove-label', t('account.remove'))
    tooltip.querySelector('input')?.setAttribute('aria-label', t('account.richTextLinkPlaceholder'))
  }
}

function handleEditorReady() {
  editorReady.value = true
  nextTick(() => {
    setEditorAccessibility()
    syncToolbar()
  })
}

function syncToolbar(event) {
  const quill = getQuill()
  if (!quill) return
  const range = event?.range ?? quill.getSelection()
  if (range) lastSelection.value = range
  // Never ask Quill for formatting without a range: that focuses the editor.
  currentFormats.value = range || lastSelection.value ? quill.getFormat(range || lastSelection.value) : {}
  nextTick(setEditorAccessibility)
}

function handleContentUpdate(value) {
  const quill = getQuill()
  const textLength = Math.max(0, (quill?.getLength() || 1) - 1)
  if (quill && textLength > props.maxlength) {
    quill.deleteText(props.maxlength, textLength - props.maxlength)
    value = editor.value.getHTML()
  }
  emit('update:modelValue', sanitizeRichText(value))
}

watch([locale, () => props.label], setEditorAccessibility)
</script>

<template>
  <div
    class="rich-text-editor"
    data-validation-field
    :data-required="required"
    :data-validation-value="validationValue"
    :data-minlength="minlength"
    :data-maxlength="maxlength"
  >
    <QuillEditor
      ref="editor"
      :content="editorContent"
      content-type="html"
      theme="snow"
      :toolbar="toolbar"
      :placeholder="placeholder || t('forms.enterField', { field: label })"
      @ready="handleEditorReady"
      @update:content="handleContentUpdate"
      @selection-change="syncToolbar"
      @text-change="syncToolbar"
    />
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/RichTextEditor.scss"></style>
