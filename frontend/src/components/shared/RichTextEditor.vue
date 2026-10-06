<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import {
  Bold,
  Code,
  Eraser,
  IndentDecrease,
  IndentIncrease,
  Italic,
  Link,
  List,
  ListOrdered,
  Quote,
  Strikethrough,
  Subscript,
  Superscript,
  Underline,
} from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { sanitizeRichText } from '../../lib/richText'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  maxlength: { type: Number, default: 1500 },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const editor = ref(null)
const editorReady = ref(false)
const currentFormats = ref({})
const lastSelection = ref(null)
const linkPanelOpen = ref(false)
const linkValue = ref('')
const linkError = ref(false)
const linkInput = ref(null)
const editorContent = computed(() => sanitizeRichText(props.modelValue))
const options = {
  formats: [
    'font',
    'size',
    'header',
    'bold',
    'italic',
    'underline',
    'strike',
    'color',
    'background',
    'script',
    'list',
    'indent',
    'align',
    'blockquote',
    'code-block',
    'link',
  ],
}

function getQuill() {
  return editorReady.value ? editor.value?.getQuill() : null
}

function setEditorAccessibility() {
  const root = editor.value?.getEditor()?.querySelector('.ql-editor')
  root?.setAttribute('aria-label', props.label)
  root?.setAttribute('role', 'textbox')
  root?.setAttribute('aria-multiline', 'true')
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
  if (range) {
    lastSelection.value = range
    currentFormats.value = quill.getFormat(range)
  } else if (lastSelection.value) {
    currentFormats.value = quill.getFormat(lastSelection.value)
  } else {
    // Calling getFormat without a range focuses Quill and scrolls to the editor.
    currentFormats.value = {}
  }
}

function handleToolbarMouseDown(event) {
  const quill = getQuill()
  const range = quill?.getSelection()
  if (range) {
    lastSelection.value = range
  }
  if (event.target.closest('button')) {
    event.preventDefault()
  }
}

function restoreSelection(quill) {
  const range = quill.getSelection() || lastSelection.value
  if (range) {
    quill.setSelection(range, 'silent')
    return range
  }

  quill.focus()
  return quill.getSelection()
}

function applyFormat(name, value, toggle = false) {
  const quill = getQuill()
  if (!quill) return

  const range = restoreSelection(quill)
  const activeFormat = quill.getFormat(range || undefined)[name]
  quill.format(name, toggle && activeFormat === value ? false : value)
  syncToolbar()
}

function handleSelectChange(name, event) {
  const value = event.target.value
  const formatValue = name === 'header'
    ? (value ? Number(value) : false)
    : (value || false)

  applyFormat(name, formatValue)
}

function toggleFormat(name, value = true) {
  applyFormat(name, value, true)
}

function toggleList(value) {
  const quill = getQuill()
  if (!quill) return

  const range = restoreSelection(quill)
  const activeList = quill.getFormat(range || undefined).list
  quill.format('list', activeList === value ? false : value)
  syncToolbar()
}

function applyIndent(value) {
  const quill = getQuill()
  if (!quill) return

  restoreSelection(quill)
  quill.format('indent', value)
  syncToolbar()
}

function clearFormatting() {
  const quill = getQuill()
  if (!quill) return

  const range = restoreSelection(quill)
  if (range) {
    quill.removeFormat(range.index, range.length)
  }
  syncToolbar()
}

function colorInputValue(format, fallback) {
  const color = currentFormats.value[format]
  if (typeof color !== 'string') return fallback
  if (/^#[\da-f]{6}$/i.test(color)) return color

  const rgb = color.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i)
  if (!rgb) return fallback

  return `#${rgb.slice(1, 4).map((value) => Number(value).toString(16).padStart(2, '0')).join('')}`
}

function applyColor(format, event) {
  applyFormat(format, event.target.value)
}

function openLinkPanel() {
  const quill = getQuill()
  if (!quill) return

  const range = quill.getSelection() || lastSelection.value
  if (range) lastSelection.value = range
  linkValue.value = quill.getFormat(range || undefined).link || ''
  linkError.value = false
  linkPanelOpen.value = !linkPanelOpen.value
  if (linkPanelOpen.value) {
    nextTick(() => linkInput.value?.focus())
  }
}

function applyLink() {
  const value = linkValue.value.trim()
  // eslint-disable-next-line no-control-regex -- Strip URL control characters before checking the scheme.
  const normalized = value.replace(/[\u0000-\u0020\u007f]+/g, '')
  const scheme = normalized.match(/^([a-z][a-z\d+.-]*):/i)?.[1]?.toLowerCase()

  if (value && (normalized.startsWith('//') || (scheme && !['http', 'https', 'mailto'].includes(scheme)))) {
    linkError.value = true
    return
  }

  applyFormat('link', value || false)
  linkPanelOpen.value = false
  linkError.value = false
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

watch(() => props.label, setEditorAccessibility)
</script>

<template>
  <div class="rich-text-editor">
    <div
      class="rich-text-editor__toolbar"
      role="toolbar"
      :aria-label="t('account.richTextToolbar')"
      @mousedown="handleToolbarMouseDown"
    >
      <div class="rich-text-editor__group">
        <select
          :value="currentFormats.font || ''"
          :aria-label="t('account.richTextFont')"
          @change="handleSelectChange('font', $event)"
        >
          <option value="">{{ t('account.richTextDefault') }}</option>
          <option value="serif">{{ t('account.richTextSerif') }}</option>
          <option value="monospace">{{ t('account.richTextMonospace') }}</option>
        </select>
        <select
          :value="currentFormats.size || ''"
          :aria-label="t('account.richTextSize')"
          @change="handleSelectChange('size', $event)"
        >
          <option value="">{{ t('account.richTextNormal') }}</option>
          <option value="small">{{ t('account.richTextSmall') }}</option>
          <option value="large">{{ t('account.richTextLarge') }}</option>
          <option value="huge">{{ t('account.richTextHuge') }}</option>
        </select>
        <select
          :value="currentFormats.header || ''"
          :aria-label="t('account.richTextHeading')"
          @change="handleSelectChange('header', $event)"
        >
          <option value="">{{ t('account.richTextParagraph') }}</option>
          <option value="1">{{ t('account.richTextHeadingOne') }}</option>
          <option value="2">{{ t('account.richTextHeadingTwo') }}</option>
          <option value="3">{{ t('account.richTextHeadingThree') }}</option>
        </select>
      </div>

      <div class="rich-text-editor__group">
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.bold }"
          type="button"
          :aria-label="t('account.richTextBold')"
          :aria-pressed="Boolean(currentFormats.bold)"
          :title="t('account.richTextBold')"
          @click="toggleFormat('bold')"
        >
          <Bold :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.italic }"
          type="button"
          :aria-label="t('account.richTextItalic')"
          :aria-pressed="Boolean(currentFormats.italic)"
          :title="t('account.richTextItalic')"
          @click="toggleFormat('italic')"
        >
          <Italic :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.underline }"
          type="button"
          :aria-label="t('account.richTextUnderline')"
          :aria-pressed="Boolean(currentFormats.underline)"
          :title="t('account.richTextUnderline')"
          @click="toggleFormat('underline')"
        >
          <Underline :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.strike }"
          type="button"
          :aria-label="t('account.richTextStrike')"
          :aria-pressed="Boolean(currentFormats.strike)"
          :title="t('account.richTextStrike')"
          @click="toggleFormat('strike')"
        >
          <Strikethrough :size="16" aria-hidden="true" />
        </button>
        <input
          class="rich-text-editor__color"
          type="color"
          :value="colorInputValue('color', '#43594d')"
          :aria-label="t('account.richTextColor')"
          :title="t('account.richTextColor')"
          @input="applyColor('color', $event)"
        />
        <input
          class="rich-text-editor__color"
          type="color"
          :value="colorInputValue('background', '#f3dfac')"
          :aria-label="t('account.richTextBackground')"
          :title="t('account.richTextBackground')"
          @input="applyColor('background', $event)"
        />
      </div>

      <div class="rich-text-editor__group">
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.script === 'sub' }"
          type="button"
          :aria-label="t('account.richTextSubscript')"
          :aria-pressed="currentFormats.script === 'sub'"
          :title="t('account.richTextSubscript')"
          @click="toggleFormat('script', 'sub')"
        >
          <Subscript :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.script === 'super' }"
          type="button"
          :aria-label="t('account.richTextSuperscript')"
          :aria-pressed="currentFormats.script === 'super'"
          :title="t('account.richTextSuperscript')"
          @click="toggleFormat('script', 'super')"
        >
          <Superscript :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.list === 'ordered' }"
          type="button"
          :aria-label="t('account.richTextNumberedList')"
          :aria-pressed="currentFormats.list === 'ordered'"
          :title="t('account.richTextNumberedList')"
          @click="toggleList('ordered')"
        >
          <ListOrdered :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.list === 'bullet' }"
          type="button"
          :aria-label="t('account.richTextBulletedList')"
          :aria-pressed="currentFormats.list === 'bullet'"
          :title="t('account.richTextBulletedList')"
          @click="toggleList('bullet')"
        >
          <List :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          type="button"
          :aria-label="t('account.richTextIndentLess')"
          :title="t('account.richTextIndentLess')"
          @click="applyIndent('-1')"
        >
          <IndentDecrease :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          type="button"
          :aria-label="t('account.richTextIndentMore')"
          :title="t('account.richTextIndentMore')"
          @click="applyIndent('+1')"
        >
          <IndentIncrease :size="16" aria-hidden="true" />
        </button>
        <select
          :value="currentFormats.align || ''"
          :aria-label="t('account.richTextAlign')"
          @change="handleSelectChange('align', $event)"
        >
          <option value="">{{ t('account.richTextAlignLeft') }}</option>
          <option value="center">{{ t('account.richTextAlignCenter') }}</option>
          <option value="right">{{ t('account.richTextAlignRight') }}</option>
          <option value="justify">{{ t('account.richTextAlignJustify') }}</option>
        </select>
      </div>

      <div class="rich-text-editor__group rich-text-editor__group--last">
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.blockquote }"
          type="button"
          :aria-label="t('account.richTextQuote')"
          :aria-pressed="Boolean(currentFormats.blockquote)"
          :title="t('account.richTextQuote')"
          @click="toggleFormat('blockquote')"
        >
          <Quote :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats['code-block'] }"
          type="button"
          :aria-label="t('account.richTextCodeBlock')"
          :aria-pressed="Boolean(currentFormats['code-block'])"
          :title="t('account.richTextCodeBlock')"
          @click="toggleFormat('code-block')"
        >
          <Code :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          :class="{ 'is-active': currentFormats.link }"
          type="button"
          :aria-label="t('account.richTextLink')"
          :aria-expanded="linkPanelOpen"
          :title="t('account.richTextLink')"
          @click="openLinkPanel"
        >
          <Link :size="16" aria-hidden="true" />
        </button>
        <button
          class="rich-text-editor__button"
          type="button"
          :aria-label="t('account.richTextClear')"
          :title="t('account.richTextClear')"
          @click="clearFormatting"
        >
          <Eraser :size="16" aria-hidden="true" />
        </button>
      </div>
    </div>

    <form
      v-if="linkPanelOpen"
      class="rich-text-editor__link-panel"
      @submit.prevent="applyLink"
    >
      <input
        ref="linkInput"
        v-model="linkValue"
        type="text"
        :aria-label="t('account.richTextLinkPlaceholder')"
        :placeholder="t('account.richTextLinkPlaceholder')"
        @input="linkError = false"
      />
      <button class="button button--dark" type="submit">
        {{ t('account.richTextApplyLink') }}
      </button>
      <button class="button button--outline" type="button" @click="linkPanelOpen = false">
        {{ t('account.richTextCancel') }}
      </button>
      <span v-if="linkError" class="rich-text-editor__link-error" role="alert">
        {{ t('account.richTextInvalidLink') }}
      </span>
    </form>

    <QuillEditor
      ref="editor"
      :content="editorContent"
      content-type="html"
      theme="snow"
      :toolbar="false"
      :options="options"
      :placeholder="placeholder"
      @ready="handleEditorReady"
      @update:content="handleContentUpdate"
      @selection-change="syncToolbar"
      @text-change="syncToolbar"
    />
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/RichTextEditor.scss"></style>
