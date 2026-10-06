<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../shared/LoadingSkeleton.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, apiRequest } from '../../lib/api'
import { localeNames } from '../../i18n'

const { locale, t } = useI18n()
const templateLocale = ref(locale.value)
const templates = ref([])
const selectedKey = ref('verify')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const previewDialog = ref(null)
const htmlEditor = ref(null)

const templateLabels = computed(() => ({
  verify: {
    title: t('adminDashboard.verifyEmailTemplate'),
    description: t('adminDashboard.verifyEmailTemplateHint'),
  },
  reset: {
    title: t('adminDashboard.resetEmailTemplate'),
    description: t('adminDashboard.resetEmailTemplateHint'),
  },
  registered: {
    title: t('adminDashboard.registeredEmailTemplate'),
    description: t('adminDashboard.registeredEmailTemplateHint'),
  },
  approval: {
    title: t('adminDashboard.approvalEmailTemplate'),
    description: t('adminDashboard.approvalEmailTemplateHint'),
  },
  contact: {
    title: t('adminDashboard.contactEmailTemplate'),
    description: t('adminDashboard.contactEmailTemplateHint'),
  },
  application_received: {
    title: t('adminDashboard.applicationReceivedEmailTemplate'),
    description: t('adminDashboard.applicationReceivedEmailTemplateHint'),
  },
  creator_inquiry_received: {
    title: t('adminDashboard.creatorInquiryEmailTemplate'),
    description: t('adminDashboard.creatorInquiryEmailTemplateHint'),
  },
  creator_inquiry_accepted: {
    title: t('adminDashboard.creatorInquiryAcceptedEmailTemplate'),
    description: t('adminDashboard.creatorInquiryAcceptedEmailTemplateHint'),
  },
  creator_hired: {
    title: t('adminDashboard.creatorHiredEmailTemplate'),
    description: t('adminDashboard.creatorHiredEmailTemplateHint'),
  },
  unread_message_reminder: {
    title: t('adminDashboard.unreadMessageReminderEmailTemplate'),
    description: t('adminDashboard.unreadMessageReminderEmailTemplateHint'),
  },
}))

const selectedTemplate = computed(() => templates.value.find(({ key }) => key === selectedKey.value) ?? null)

const previewHtml = computed(() => {
  if (!selectedTemplate.value) return ''
  const previewSamples = selectedTemplate.value.preview ?? {}

  return selectedTemplate.value.htmlBody.replace(
    /\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/g,
    (_, key) => escapeHtml(previewSamples[key] ?? `Example ${key}`),
  )
})

function escapeHtml(value) {
  return value.replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[character])
}

function variableToken(variable) {
  return `{{${variable}}}`
}

async function loadTemplates() {
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiGet('/admin/email-templates', { locale: templateLocale.value })
    templates.value = data.templates
    if (!templates.value.some(({ key }) => key === selectedKey.value)) {
      selectedKey.value = templates.value[0]?.key ?? 'verify'
    }
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.emailTemplatesLoadError')
  } finally {
    loading.value = false
  }
}

async function saveTemplate() {
  if (!selectedTemplate.value || saving.value) return

  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/admin/email-templates/${selectedTemplate.value.key}`, {
      method: 'PUT',
      locale: templateLocale.value,
      body: {
        subject: selectedTemplate.value.subject,
        htmlBody: selectedTemplate.value.htmlBody,
      },
    })
    notice.value = t('adminDashboard.emailTemplateSaved')
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.emailTemplateSaveError')
  } finally {
    saving.value = false
  }
}

async function showPreview() {
  await nextTick()
  if (previewDialog.value && !previewDialog.value.open) {
    previewDialog.value.showModal()
  }
}

function closePreview() {
  if (previewDialog.value?.open) {
    previewDialog.value.close()
  }
}

async function insertVariable(variable) {
  if (!selectedTemplate.value) return

  const editor = htmlEditor.value
  const start = editor?.selectionStart ?? selectedTemplate.value.htmlBody.length
  const end = editor?.selectionEnd ?? start
  const token = `{{${variable}}}`
  selectedTemplate.value.htmlBody = [
    selectedTemplate.value.htmlBody.slice(0, start),
    token,
    selectedTemplate.value.htmlBody.slice(end),
  ].join('')

  await nextTick()
  editor?.focus()
  editor?.setSelectionRange(start + token.length, start + token.length)
}

watch(locale, (newLocale) => {
  templateLocale.value = newLocale
})
watch(templateLocale, loadTemplates)
onMounted(loadTemplates)
</script>

<template>
  <section class="email-template-manager">
    <header class="email-template-manager__intro">
      <p class="eyebrow">{{ t('adminDashboard.emailTemplatesEyebrow') }}</p>
      <h2>{{ t('adminDashboard.emailTemplatesTitle') }}</h2>
      <p>{{ t('adminDashboard.emailTemplatesDescription') }}</p>
    </header>

    <label class="form-field email-template-locale">
      <span>{{ t('adminDashboard.emailTemplateLanguage') }}</span>
      <select v-model="templateLocale">
        <option v-for="(name, code) in localeNames" :key="code" :value="code">
          {{ name }} ({{ code.toUpperCase() }})
        </option>
      </select>
    </label>

    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-if="notice">{{ notice }}</StatusMessage>
    <LoadingSkeleton v-if="loading" variant="account" :label="t('adminDashboard.loading')" />

    <div v-if="!loading && templates.length" class="email-template-manager__layout">
      <aside class="email-template-list" :aria-label="t('adminDashboard.chooseEmailTemplate')">
        <h3>{{ t('adminDashboard.chooseEmailTemplate') }}</h3>
        <button
          v-for="template in templates"
          :key="template.key"
          class="email-template-list__item"
          :class="{ 'is-active': selectedKey === template.key }"
          type="button"
          :aria-pressed="selectedKey === template.key"
          @click="selectedKey = template.key"
        >
          <strong>{{ templateLabels[template.key]?.title ?? template.key }}</strong>
          <span>{{ template.subject }}</span>
          <small>{{ templateLabels[template.key]?.description }}</small>
        </button>
      </aside>

      <form v-if="selectedTemplate" class="email-template-editor" @submit.prevent="saveTemplate">
        <header class="email-template-editor__heading">
          <div>
            <p class="eyebrow">{{ t('adminDashboard.emailTemplatesEyebrow') }}</p>
            <h3>{{ templateLabels[selectedTemplate.key]?.title ?? selectedTemplate.key }}</h3>
          </div>
          <button class="button button--outline" type="button" @click="showPreview">
            {{ t('adminDashboard.previewEmailTemplate') }}
          </button>
        </header>

        <label class="form-field">
          <span>{{ t('adminDashboard.emailTemplateSubject') }}</span>
          <input v-model.trim="selectedTemplate.subject" type="text" maxlength="180" required />
        </label>

        <label class="form-field email-template-editor__html-field">
          <span>{{ t('adminDashboard.emailTemplateHtml') }}</span>
          <textarea
            ref="htmlEditor"
            v-model="selectedTemplate.htmlBody"
            rows="20"
            spellcheck="false"
            autocapitalize="off"
            autocomplete="off"
            required
          ></textarea>
        </label>

        <section class="email-template-variables" :aria-label="t('adminDashboard.emailTemplateVariables')">
          <header>
            <h4>{{ t('adminDashboard.emailTemplateVariables') }}</h4>
            <p>{{ t('adminDashboard.emailTemplateVariablesHint') }}</p>
          </header>
          <div class="email-template-variables__list">
            <button
              v-for="variable in selectedTemplate.variables"
              :key="variable"
              type="button"
              @click="insertVariable(variable)"
            >
              <code>{{ variableToken(variable) }}</code>
              <span>{{ t('adminDashboard.insertEmailVariable') }}</span>
            </button>
          </div>
        </section>

        <footer class="email-template-editor__actions">
          <p>{{ t('adminDashboard.emailTemplateLocaleHint', { locale: templateLocale.toUpperCase() }) }}</p>
          <button class="button button--dark" type="submit" :disabled="saving">
            {{ saving ? t('adminDashboard.saving') : t('adminDashboard.saveEmailTemplate') }}
          </button>
        </footer>
      </form>
    </div>

    <dialog
      ref="previewDialog"
      class="email-template-preview"
      aria-modal="true"
      aria-labelledby="email-template-preview-title"
      @cancel.prevent="closePreview"
      @click.self="closePreview"
    >
      <section class="email-template-preview__content">
        <header>
          <div>
            <p class="eyebrow">{{ t('adminDashboard.emailTemplatesEyebrow') }}</p>
            <h2 id="email-template-preview-title">{{ t('adminDashboard.emailTemplatePreviewTitle') }}</h2>
          </div>
          <button class="email-template-preview__close" type="button" @click="closePreview">
            {{ t('adminDashboard.closePreview') }}
          </button>
        </header>
        <p class="email-template-preview__hint">{{ t('adminDashboard.emailTemplatePreviewHint') }}</p>
        <iframe
          class="email-template-preview__frame"
          :srcdoc="previewHtml"
          sandbox=""
          :title="t('adminDashboard.emailTemplatePreviewTitle')"
        ></iframe>
      </section>
    </dialog>
  </section>
</template>
