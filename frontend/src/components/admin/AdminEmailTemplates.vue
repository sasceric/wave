<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Clock, Mail, RotateCcw, Search, Send, UserRound, Zap } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, useRoute } from 'vue-router'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import EmailPreviewFrame from '../shared/EmailPreviewFrame.vue'
import LoadingSkeleton from '../shared/LoadingSkeleton.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, apiRequest } from '../../lib/api'
import { EMAIL_TEMPLATE_GROUPS, renderTemplate, templateMeta } from '../../lib/emailTemplateCatalog'
import { localeNames } from '../../i18n'

const { locale, t } = useI18n()
const route = useRoute()
const templateLocale = ref(locale.value)
const templates = ref([])
const selectedKey = ref(typeof route.query.template === 'string' ? route.query.template : 'verify')
const loading = ref(false)
const busy = ref('')
const error = ref('')
const notice = ref('')
const query = ref('')
const filter = ref('all')
const tab = ref('text')
const device = ref('desktop')
const htmlEditor = ref(null)
const previewContainer = ref(null)
const previewWidth = ref(640)
let previewObserver = null
const draft = ref(null)
const saved = ref('')
const pending = ref(null)
const confirmReset = ref(false)
const loadedLocale = ref('')
const sender = ref({ name: '', email: '' })
let loadId = 0
let leaveDecision = null

const templateLabels = computed(() => ({
  verification_reminder: { title: t('adminProfiles.verificationTemplateTitle'), description: t('adminProfiles.verificationTemplateHint') },
  approval_revoked: { title: t('adminProfiles.unapprovedTemplateTitle'), description: t('adminProfiles.unapprovedTemplateHint') },
  pre_approval: { title: t('adminProfiles.preApprovalTemplateTitle'), description: t('adminProfiles.preApprovalTemplateHint') },
  profile_tip: { title: t('adminProfiles.profileTipTemplateTitle'), description: t('adminProfiles.profileTipTemplateHint') },
  credit_code_assigned: { title: t('credits.assignmentEmailTitle'), description: t('credits.assignmentEmailHint') },
  credits_activated: { title: t('credits.emailTitle'), description: t('credits.emailHint') },
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
  approval_company: {
    title: t('adminDashboard.approvalCompanyEmailTemplate'),
    description: t('adminDashboard.approvalCompanyEmailTemplateHint'),
  },
  admin_broadcast: {
    title: t('adminDashboard.adminBroadcastEmailTemplate'),
    description: t('adminDashboard.adminBroadcastEmailTemplateHint'),
  },
  contact: {
    title: t('adminDashboard.contactEmailTemplate'),
    description: t('adminDashboard.contactEmailTemplateHint'),
  },
  subscribed: {
    title: t('adminDashboard.subscribedEmailTemplate'),
    description: t('adminDashboard.subscribedEmailTemplateHint'),
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
  campaign_invitation: {
    title: t('adminDashboard.campaignInvitationEmailTemplate'),
    description: t('adminDashboard.campaignInvitationEmailTemplateHint'),
  },
  creator_hired: {
    title: t('adminDashboard.creatorHiredEmailTemplate'),
    description: t('adminDashboard.creatorHiredEmailTemplateHint'),
  },
  unread_message_reminder: {
    title: t('adminDashboard.unreadMessageReminderEmailTemplate'),
    description: t('adminDashboard.unreadMessageReminderEmailTemplateHint'),
  },
  // One layout for the four profile tips emails (days 1, 4, 10 and 21).
  profile_reminder: {
    title: t('adminDashboard.profileReminderEmailTemplate'),
    description: t('adminDashboard.profileReminderEmailTemplateHint'),
  },
  campaign_alert: {
    title: t('adminDashboard.campaignAlertEmailTemplate'),
    description: t('adminDashboard.campaignAlertEmailTemplateHint'),
  },
  account_deleted: {
    title: t('adminDashboard.accountDeletedEmailTemplate'),
    description: t('adminDashboard.accountDeletedEmailTemplateHint'),
  },
  terms_updated: {
    title: t('adminDashboard.termsUpdatedEmailTemplate'),
    description: t('adminDashboard.termsUpdatedEmailTemplateHint'),
  },
}))

const localeCodes = ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']
const selectedTemplate = computed(() => templates.value.find(({ key }) => key === selectedKey.value) ?? null)
const dirty = computed(() => Boolean(draft.value) && snapshot(draft.value) !== saved.value)
const customized = computed(() => Boolean(selectedTemplate.value?.customized))
const meta = computed(() => templateMeta(selectedKey.value))
const customCount = computed(() => templates.value.filter((template) => template.customizedLocales.length).length)

const groups = computed(() => {
  const search = query.value.trim().toLocaleLowerCase()
  const visible = templates.value.filter((template) => {
    if (filter.value === 'custom' && !template.customized) return false
    if (filter.value === 'default' && template.customized) return false
    if (!search) return true
    const label = templateLabels.value[template.key]
    return `${label?.title ?? template.key} ${label?.description ?? ''} ${template.subject} ${t(`emailTemplates.recipients.${templateMeta(template.key).recipient}`)}`
      .toLocaleLowerCase().includes(search)
  })
  return EMAIL_TEMPLATE_GROUPS
    .map((group) => ({ group, items: visible.filter((template) => templateMeta(template.key).group === group) }))
    .filter(({ items }) => items.length)
})

const previewValues = computed(() => {
  if (!selectedTemplate.value || !draft.value) return {}
  return { ...selectedTemplate.value.preview, ...draft.value.text }
})
const previewHtml = computed(() => (draft.value ? renderTemplate(draft.value.htmlBody, previewValues.value) : ''))
const previewViewportWidth = computed(() => device.value === 'mobile' ? 375 : 640)
const previewScale = computed(() => Math.min(1, previewWidth.value / previewViewportWidth.value))
const previewFrameStyle = computed(() => ({ width: `${previewViewportWidth.value}px`, height: `${640 / Math.max(.1, previewScale.value)}px`, transform: `scale(${previewScale.value})` }))
const previewSubject = computed(() => (draft.value ? renderTemplate(draft.value.subject, previewValues.value, false) : ''))
const statusLabel = computed(() => {
  if (dirty.value) return t('emailTemplates.statusDirty')
  return customized.value ? t('emailTemplates.statusCustom', { locale: templateLocale.value.toUpperCase() }) : t('emailTemplates.statusDefault')
})
const statusTone = computed(() => (dirty.value ? 'dirty' : customized.value ? 'custom' : 'default'))

function snapshot(value) {
  return JSON.stringify({ subject: value.subject, htmlBody: value.htmlBody, text: value.text })
}

function resetDraft() {
  const template = selectedTemplate.value
  if (!template) { draft.value = null; saved.value = ''; return }
  draft.value = {
    subject: template.subject,
    htmlBody: template.htmlBody,
    text: Object.fromEntries(template.text.map((field) => [field.key, field.value])),
  }
  saved.value = snapshot(draft.value)
}

function variableToken(variable) {
  return `{{${variable}}}`
}

function textLabel(key) {
  return t(`emailTemplates.fields.${key}`)
}

function isFieldChanged(field) {
  return draft.value?.text[field.key] !== field.default
}

function restoreField(field) {
  draft.value.text[field.key] = field.default
}

async function loadTemplates() {
  const requestId = ++loadId
  const language = templateLocale.value
  loading.value = true
  error.value = ''
  draft.value = null
  try {
    const { data } = await apiGet('/admin/email-templates', { locale: language })
    if (requestId !== loadId) return
    templates.value = data.templates
    sender.value = data.sender
    loadedLocale.value = language
    if (!templates.value.some(({ key }) => key === selectedKey.value)) selectedKey.value = templates.value[0]?.key ?? 'verify'
    resetDraft()
  } catch (cause) {
    if (requestId !== loadId) return
    loadedLocale.value = ''
    error.value = cause.message || t('adminDashboard.emailTemplatesLoadError')
  } finally {
    if (requestId === loadId) loading.value = false
  }
}

// Changing template or language with unsaved edits asks first.
function guard(action) {
  if (dirty.value) { pending.value = action; return }
  action()
}

function selectTemplate(key) {
  if (loading.value || busy.value || loadedLocale.value !== templateLocale.value || key === selectedKey.value) return
  guard(() => { selectedKey.value = key; notice.value = ''; resetDraft() })
}

function selectLocale(code) {
  if (busy.value || code === templateLocale.value) return
  guard(() => { templateLocale.value = code; notice.value = '' })
}

function discardAndContinue() {
  const action = pending.value
  pending.value = null
  draft.value = null
  saved.value = ''
  const resolve = leaveDecision
  leaveDecision = null
  if (resolve) resolve(true)
  else action?.()
}

function cancelPending() {
  pending.value = null
  leaveDecision?.(false)
  leaveDecision = null
}

function body() {
  return { subject: draft.value.subject.trim(), htmlBody: draft.value.htmlBody, text: draft.value.text }
}

async function saveTemplate() {
  if (!draft.value || busy.value || loading.value || loadedLocale.value !== templateLocale.value) return
  busy.value = 'save'
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest(`/admin/email-templates/${selectedKey.value}`, { method: 'PUT', locale: templateLocale.value, body: body() })
    const template = selectedTemplate.value
    template.customized = data.customized
    template.customizedLocales = data.customized
      ? [...new Set([...template.customizedLocales, templateLocale.value])]
      : template.customizedLocales.filter((code) => code !== templateLocale.value)
    template.subject = draft.value.subject.trim()
    template.htmlBody = draft.value.htmlBody
    template.text = template.text.map((field) => ({ ...field, value: draft.value.text[field.key] }))
    resetDraft()
    notice.value = t('adminDashboard.emailTemplateSaved')
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.emailTemplateSaveError')
  } finally {
    busy.value = ''
  }
}

async function sendTest() {
  if (!draft.value || busy.value || loading.value || loadedLocale.value !== templateLocale.value) return
  busy.value = 'test'
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest(`/admin/email-templates/${selectedKey.value}/test`, { method: 'POST', locale: templateLocale.value, body: body() })
    notice.value = t('emailTemplates.testSent', { email: data.sentTo })
  } catch (cause) {
    error.value = cause.message || t('emailTemplates.testError')
  } finally {
    busy.value = ''
  }
}

async function resetTemplate() {
  if (busy.value || loading.value || loadedLocale.value !== templateLocale.value) return
  busy.value = 'reset'
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/admin/email-templates/${selectedKey.value}`, { method: 'DELETE', locale: templateLocale.value })
    confirmReset.value = false
    draft.value = null
    saved.value = ''
    await loadTemplates()
    notice.value = t('emailTemplates.resetDone', { locale: templateLocale.value.toUpperCase() })
  } catch (cause) {
    error.value = cause.message || t('adminDashboard.emailTemplateSaveError')
    confirmReset.value = false
  } finally {
    busy.value = ''
  }
}

async function insertVariable(variable) {
  if (!draft.value) return
  const editor = htmlEditor.value
  const start = editor?.selectionStart ?? draft.value.htmlBody.length
  const end = editor?.selectionEnd ?? start
  const token = variableToken(variable)
  draft.value.htmlBody = `${draft.value.htmlBody.slice(0, start)}${token}${draft.value.htmlBody.slice(end)}`
  await nextTick()
  editor?.focus()
  editor?.setSelectionRange(start + token.length, start + token.length)
}

watch(previewContainer, (element) => {
  previewObserver?.disconnect()
  if (!element) return
  previewWidth.value = Math.max(1, element.clientWidth - 24)
  previewObserver = new ResizeObserver(([entry]) => { previewWidth.value = Math.max(1, entry.contentRect.width) })
  previewObserver.observe(element)
})

watch(locale, (newLocale) => { if (!dirty.value) templateLocale.value = newLocale })
watch(templateLocale, loadTemplates)
watch(() => route.query.template, (key) => {
  if (typeof key === 'string' && templates.value.some(template => template.key === key)) selectTemplate(key)
})
onBeforeRouteLeave(() => {
  if (busy.value) return false
  if (!dirty.value) return true
  return new Promise(resolve => {
    leaveDecision = resolve
    pending.value = () => {}
  })
})
function beforeUnload(event) {
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
onMounted(() => {
  window.addEventListener('beforeunload', beforeUnload)
  void loadTemplates()
})
onBeforeUnmount(() => {
  loadId++
  previewObserver?.disconnect()
  window.removeEventListener('beforeunload', beforeUnload)
  cancelPending()
})
</script>

<template>
  <section class="email-templates">
    <header class="email-templates__intro">
      <p>{{ t('emailTemplates.intro') }}</p>
      <dl class="email-templates__stats">
        <div><dt>{{ t('emailTemplates.templatesCount') }}</dt><dd>{{ templates.length }}</dd></div>
        <div><dt>{{ t('emailTemplates.customCount') }}</dt><dd class="is-custom">{{ customCount }}</dd></div>
      </dl>
    </header>

    <StatusMessage v-if="error" variant="error">{{ error }} <button v-if="loadedLocale !== templateLocale && !loading" class="text-link" type="button" @click="loadTemplates">{{ t('directoryLoading.retry') }}</button></StatusMessage>
    <StatusMessage v-if="notice" variant="success">{{ notice }}</StatusMessage>
    <LoadingSkeleton v-if="loading" variant="account" :label="t('adminDashboard.loading')" />

    <div v-if="templates.length" class="email-templates__layout" :aria-busy="loading">
      <aside class="email-templates__sidebar" :aria-label="t('adminDashboard.chooseEmailTemplate')">
        <label class="email-templates__search">
          <Search :size="16" aria-hidden="true" />
          <span class="sr-only">{{ t('emailTemplates.search') }}</span>
          <input v-model="query" type="search" :placeholder="t('emailTemplates.search')" />
        </label>
        <div class="email-templates__segmented" role="group" :aria-label="t('emailTemplates.filter')">
          <button v-for="option in ['all', 'custom', 'default']" :key="option" type="button" :class="{ 'is-active': filter === option }" :aria-pressed="filter === option" @click="filter = option">
            {{ t(`emailTemplates.filters.${option}`) }}
          </button>
        </div>
        <nav class="email-templates__list">
          <section v-for="{ group, items } in groups" :key="group">
            <h3>{{ t(`emailTemplates.groups.${group}`) }} · {{ items.length }}</h3>
            <button
              v-for="template in items"
              :key="template.key"
              type="button"
              class="email-templates__item"
              :class="{ 'is-active': template.key === selectedKey }"
              :aria-current="template.key === selectedKey ? 'true' : undefined"
              :disabled="loading || Boolean(busy) || loadedLocale !== templateLocale"
              @click="selectTemplate(template.key)"
            >
              <span class="email-templates__item-icon" aria-hidden="true"><Mail :size="16" /></span>
              <span class="email-templates__item-copy">
                <span class="email-templates__item-title">
                  <strong>{{ templateLabels[template.key]?.title ?? template.key }}</strong>
                  <span v-if="template.customized" class="email-templates__badge">{{ templateLocale.toUpperCase() }}</span>
                </span>
                <small>{{ t(`emailTemplates.recipients.${templateMeta(template.key).recipient}`) }} · {{ t(`emailTemplates.frequency.${templateMeta(template.key).frequency}`) }}</small>
              </span>
            </button>
          </section>
          <p v-if="!groups.length" class="email-templates__empty">{{ t('emailTemplates.noResults') }}</p>
        </nav>
      </aside>

      <form v-if="selectedTemplate && draft && loadedLocale === templateLocale" v-form-validation class="email-templates__main" @submit.prevent="saveTemplate">
        <fieldset class="email-templates__fields" :disabled="loading || Boolean(busy)">
        <section class="email-templates__card email-templates__summary">
          <header>
            <div>
              <p class="eyebrow">{{ t(`emailTemplates.groups.${meta.group}`) }}</p>
              <h3>{{ templateLabels[selectedKey]?.title ?? selectedKey }}</h3>
            </div>
            <span class="email-templates__status" :class="`is-${statusTone}`">{{ statusLabel }}</span>
          </header>
          <dl class="email-templates__facts">
            <div><dt><Zap :size="14" aria-hidden="true" />{{ t('emailTemplates.whenSent') }}</dt><dd>{{ templateLabels[selectedKey]?.description }}</dd></div>
            <div><dt><UserRound :size="14" aria-hidden="true" />{{ t('emailTemplates.recipient') }}</dt><dd>{{ t(`emailTemplates.recipients.${meta.recipient}`) }}</dd></div>
            <div><dt><Clock :size="14" aria-hidden="true" />{{ t('emailTemplates.frequencyLabel') }}</dt><dd>{{ t(`emailTemplates.frequency.${meta.frequency}`) }}</dd></div>
          </dl>
          <div class="email-templates__languages">
            <span id="email-template-language">{{ t('emailTemplates.language') }} <small>· {{ t('emailTemplates.languageHint') }}</small></span>
            <div role="group" aria-labelledby="email-template-language">
              <button
                v-for="code in localeCodes"
                :key="code"
                type="button"
                :class="{ 'is-active': code === templateLocale }"
                :aria-pressed="code === templateLocale"
                :title="localeNames[code]"
                :disabled="loading"
                @click="selectLocale(code)"
              >
                {{ code.toUpperCase() }}
                <i v-if="selectedTemplate.customizedLocales.includes(code)" :aria-label="t('emailTemplates.customizedDot')"></i>
              </button>
            </div>
          </div>
        </section>

        <div class="email-templates__workspace">
          <section class="email-templates__card email-templates__editor">
            <div class="email-templates__segmented email-templates__tabs" role="tablist" :aria-label="t('emailTemplates.editing')">
              <button v-for="option in ['text', 'html']" :key="option" type="button" role="tab" :aria-selected="tab === option" :class="{ 'is-active': tab === option }" @click="tab = option">
                {{ t(`emailTemplates.tabs.${option}`) }}
              </button>
            </div>

            <label class="form-field">
              <span>{{ t('adminDashboard.emailTemplateSubject') }}</span>
              <input v-model="draft.subject" type="text" maxlength="180" required />
              <small v-if="draft.subject !== selectedTemplate.defaultSubject" class="email-templates__field-reset">
                <button type="button" @click="draft.subject = selectedTemplate.defaultSubject"><RotateCcw :size="12" aria-hidden="true" />{{ t('emailTemplates.restoreField') }}</button>
              </small>
            </label>

            <template v-if="tab === 'text'">
              <p class="email-templates__hint">{{ t('emailTemplates.textHint') }}</p>
              <p v-if="!selectedTemplate.text.length" class="email-templates__hint">{{ t('emailTemplates.noTextFields') }}</p>
              <label v-for="field in selectedTemplate.text" :key="field.key" class="form-field">
                <span>{{ textLabel(field.key) }}</span>
                <textarea v-if="field.multiline" v-model="draft.text[field.key]" rows="4" maxlength="2000" required></textarea>
                <input v-else v-model="draft.text[field.key]" type="text" maxlength="300" required />
                <small v-if="isFieldChanged(field)" class="email-templates__field-reset">
                  <button type="button" @click="restoreField(field)"><RotateCcw :size="12" aria-hidden="true" />{{ t('emailTemplates.restoreField') }}</button>
                </small>
              </label>
            </template>

            <template v-else>
              <div class="email-templates__variables" role="group" :aria-label="t('adminDashboard.emailTemplateVariables')">
                <span>{{ t('emailTemplates.insert') }}</span>
                <button v-for="variable in selectedTemplate.variables" :key="variable" type="button" @click="insertVariable(variable)" v-text="variableToken(variable)"></button>
              </div>
              <label class="form-field">
                <span class="sr-only">{{ t('adminDashboard.emailTemplateHtml') }}</span>
                <textarea ref="htmlEditor" v-model="draft.htmlBody" class="email-templates__code" rows="18" spellcheck="false" autocapitalize="off" autocomplete="off" required></textarea>
              </label>
              <p class="email-templates__hint">{{ t('emailTemplates.htmlHint') }}
                <button v-if="draft.htmlBody !== selectedTemplate.defaultHtmlBody" type="button" class="text-link" @click="draft.htmlBody = selectedTemplate.defaultHtmlBody">{{ t('emailTemplates.restoreLayout') }}</button>
              </p>
            </template>
          </section>

          <section class="email-templates__card email-templates__preview" :aria-label="t('emailTemplates.preview')">
            <header>
              <div>
                <strong>{{ t('emailTemplates.preview') }}</strong>
                <small>{{ t('emailTemplates.previewSample', { language: localeNames[templateLocale] }) }}</small>
              </div>
              <div class="email-templates__segmented" role="group" :aria-label="t('emailTemplates.device')">
                <button v-for="option in ['desktop', 'mobile']" :key="option" type="button" :class="{ 'is-active': device === option }" :aria-pressed="device === option" @click="device = option">
                  {{ t(`emailTemplates.devices.${option}`) }}
                </button>
              </div>
            </header>
            <dl class="email-templates__envelope">
              <div><dt>{{ t('emailTemplates.from') }}</dt><dd>{{ sender.name }} &lt;{{ sender.email }}&gt;</dd></div>
              <div><dt>{{ t('adminDashboard.emailTemplateSubject') }}</dt><dd><strong>{{ previewSubject }}</strong></dd></div>
            </dl>
            <div ref="previewContainer" class="email-templates__frame-wrap">
              <div class="email-templates__frame-viewport" :style="{ width: `${previewViewportWidth * previewScale}px` }">
              <EmailPreviewFrame class="email-templates__frame" :class="`is-${device}`" :style="previewFrameStyle" :html="previewHtml" allow-brand-images :title="t('adminDashboard.emailTemplatePreviewTitle')" />
              </div>
            </div>
          </section>
        </div>

        <footer class="email-templates__actions">
          <span class="email-templates__save-state" :class="{ 'is-dirty': dirty }" role="status">
            <i aria-hidden="true"></i>{{ dirty ? t('emailTemplates.unsaved', { locale: templateLocale.toUpperCase() }) : t('emailTemplates.allSaved', { locale: templateLocale.toUpperCase() }) }}
          </span>
          <div>
            <button type="button" class="button button--outline email-templates__reset" :disabled="(!customized && !dirty) || Boolean(busy)" @click="confirmReset = true">
              <RotateCcw :size="15" aria-hidden="true" />{{ t('emailTemplates.reset') }}
            </button>
            <button type="button" class="button button--outline" :disabled="Boolean(busy)" @click="sendTest">
              <Send :size="15" aria-hidden="true" />{{ busy === 'test' ? t('adminDashboard.saving') : t('emailTemplates.sendTest') }}
            </button>
            <button type="submit" class="button button--dark" :disabled="Boolean(busy) || !dirty">
              {{ busy === 'save' ? t('adminDashboard.saving') : t('emailTemplates.save', { locale: templateLocale.toUpperCase() }) }}
            </button>
          </div>
        </footer>
        </fieldset>
      </form>
    </div>

    <ConfirmationModal
      :open="confirmReset"
      :title="t('emailTemplates.resetTitle')"
      :message="t('emailTemplates.resetMessage', { locale: templateLocale.toUpperCase() })"
      :confirm-label="t('emailTemplates.reset')"
      :cancel-label="t('adminDashboard.cancelAction')"
      :loading="busy === 'reset'"
      @update:open="confirmReset = $event"
      @confirm="resetTemplate"
    />
    <ConfirmationModal
      :open="pending !== null"
      :title="t('emailTemplates.discardTitle')"
      :message="t('emailTemplates.discardMessage')"
      :confirm-label="t('emailTemplates.discard')"
      :cancel-label="t('emailTemplates.keepEditing')"
      @update:open="!$event && cancelPending()"
      @confirm="discardAndContinue"
    />
  </section>
</template>

<style lang="scss" src="../../scss/components/admin/AdminEmailTemplates.scss"></style>
