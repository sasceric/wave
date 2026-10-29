<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { MailCheck, RotateCcw, Send } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import TagInput from '../components/shared/TagInput.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'
import { BROADCAST_VARIABLES, defaultBroadcast, isEmailAddress, personaliseBroadcast, unknownBroadcastVariables } from '../lib/broadcastMessage'

const SUBJECT_MAX = 150
const MESSAGE_MAX = 10000
const EMAILS_MAX = 2000
const { locale, t } = useI18n()
const access = ref('loading')
const content = ref(defaultBroadcast(locale.value))
const audience = ref('all')
const role = ref('creators')
const counts = ref({ all: 0, newsletter: 0 })
const userIds = ref([])
const userOptions = ref([])
const selectedUsers = ref(new Map())
const users = ref({ loading: false, hasMore: false, page: 1, query: '', error: false })
const emails = ref([])
const confirming = ref(false)
const sending = ref(false)
const testing = ref(false)
const error = ref('')
const notice = ref('')
const editor = ref(null)
let usersRevision = 0

const audiences = computed(() => [
  { value: 'all', label: t('adminBroadcast.audienceAll'), hint: t('adminBroadcast.audienceAllHint') },
  { value: 'newsletter', label: t('adminBroadcast.audienceNewsletter'), hint: t('adminBroadcast.audienceNewsletterHint') },
  { value: 'users', label: t('adminBroadcast.audienceUsers'), hint: t('adminBroadcast.audienceUsersHint') },
  { value: 'emails', label: t('adminBroadcast.audienceEmails'), hint: t('adminBroadcast.audienceEmailsHint') },
])
const roleOptions = computed(() => [
  { value: 'creators', label: t('adminBroadcast.roleCreators') },
  { value: 'companies', label: t('adminBroadcast.roleCompanies') },
  { value: 'all', label: t('adminBroadcast.roleAll') },
])
const invalidEmails = computed(() => emails.value.filter((value) => !isEmailAddress(value)))
const recipientCount = computed(() => ({
  all: counts.value.all,
  newsletter: counts.value.newsletter,
  users: userIds.value.length,
  emails: emails.value.length,
})[audience.value])
const unknownVariables = computed(() => unknownBroadcastVariables(content.value.subject, content.value.heading, content.value.message, content.value.buttonLabel, content.value.buttonUrl))
const urlValid = computed(() => /^\{\{\s*profileUrl\s*\}\}$/.test(content.value.buttonUrl.trim()) || /^https:\/\/\S+$/.test(content.value.buttonUrl.trim()))
const contentValid = computed(() => {
  const { subject, heading, message, buttonLabel } = content.value
  return subject.trim() && subject.length <= SUBJECT_MAX && heading.trim() && heading.length <= SUBJECT_MAX
    && message.trim().length >= 10 && message.length <= MESSAGE_MAX && buttonLabel.trim() && urlValid.value && !unknownVariables.value.length
})
const canSend = computed(() => contentValid.value && recipientCount.value > 0 && !sending.value && !(audience.value === 'emails' && invalidEmails.value.length))
const preview = computed(() => {
  const values = { name: t('adminBroadcast.previewName'), profileUrl: 'https://wave.ba/kreatori/amina' }
  return {
    subject: personaliseBroadcast(content.value.subject, values),
    heading: personaliseBroadcast(content.value.heading, values),
    message: personaliseBroadcast(content.value.message, values),
    buttonLabel: personaliseBroadcast(content.value.buttonLabel, values),
  }
})
const selectionOptions = computed(() => [...selectedUsers.value.values()])

async function loadCounts() {
  try {
    const { data } = await apiGet(`/admin/broadcast/audience?role=${role.value}`)
    counts.value = data
    access.value = 'allowed'
  } catch (cause) {
    if (cause.status === 401) access.value = 'anonymous'
    else if (cause.status === 403) access.value = 'forbidden'
    else { error.value = cause.message || t('adminBroadcast.loadError'); access.value = 'error' }
  }
}

async function loadUsers(reset = false) {
  if (users.value.loading && !reset) return
  const revision = ++usersRevision
  if (reset) { userOptions.value = []; users.value.page = 1 }
  users.value.loading = true
  users.value.error = false
  try {
    const params = new URLSearchParams({ page: String(users.value.page) })
    if (users.value.query.trim()) params.set('q', users.value.query.trim())
    const { data } = await apiGet(`/admin/broadcast/users?${params}`)
    if (revision !== usersRevision) return
    userOptions.value = [...new Map([...userOptions.value, ...data.rows].map((row) => [row.value, row])).values()]
    users.value.hasMore = data.hasMore
    users.value.page += 1
  } catch {
    if (revision === usersRevision) users.value.error = true
  } finally {
    if (revision === usersRevision) users.value.loading = false
  }
}

function searchUsers(query) {
  users.value.query = query
  loadUsers(true)
}

function selectUsers(values) {
  const known = new Map([...selectedUsers.value, ...userOptions.value.map((row) => [row.value, row])])
  selectedUsers.value = new Map(values.filter((value) => known.has(value)).map((value) => [value, known.get(value)]))
  userIds.value = values
}

function variableToken(name) {
  return `{{${name}}}`
}

async function insertVariable(name) {
  const field = editor.value
  const token = variableToken(name)
  if (!field) { content.value.message += token; return }
  const start = field.selectionStart ?? content.value.message.length
  const end = field.selectionEnd ?? start
  content.value.message = `${content.value.message.slice(0, start)}${token}${content.value.message.slice(end)}`
  field.focus()
}

function resetContent() {
  content.value = defaultBroadcast(locale.value)
}

function body(test = false) {
  return {
    ...content.value,
    audience: audience.value,
    role: role.value,
    ...(audience.value === 'users' ? { userIds: userIds.value } : {}),
    ...(audience.value === 'emails' ? { emails: emails.value } : {}),
    ...(test ? { test: true } : {}),
  }
}

async function sendTest() {
  if (!contentValid.value || testing.value) return
  testing.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest('/admin/broadcast', { method: 'POST', body: body(true) })
    notice.value = t('adminBroadcast.testSent', { email: currentUser.value?.email || '' })
  } catch (cause) {
    error.value = cause.message || t('adminBroadcast.sendError')
  } finally {
    testing.value = false
  }
}

async function send() {
  if (!canSend.value) return
  sending.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest('/admin/broadcast', { method: 'POST', body: body() })
    notice.value = data.skipped ? t('adminBroadcast.sentWithSkipped', { count: data.sent, skipped: data.skipped }) : t('adminBroadcast.sent', { count: data.sent })
    confirming.value = false
    userIds.value = []
    selectedUsers.value = new Map()
    emails.value = []
  } catch (cause) {
    error.value = cause.message || t('adminBroadcast.sendError')
    confirming.value = false
  } finally {
    sending.value = false
  }
}

watch(role, loadCounts)
watch(audience, (value) => { if (value === 'users' && !userOptions.value.length) loadUsers(true) })
watch(() => currentUser.value?.id, () => { access.value = 'loading'; loadCounts() })
onMounted(loadCounts)
</script>

<template>
  <AdminPage class="admin-invitations admin-broadcast">
    <div v-if="access === 'loading'" class="admin-dashboard__layout">
      <AccountSidebar v-if="currentUser" :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main"><LoadingSkeleton variant="dashboard" :label="t('adminDashboard.loading')" /></main>
    </div>
    <div v-else-if="access === 'anonymous'" class="admin-dashboard__state">
      <StatusMessage>{{ t('adminDashboard.signIn') }}</StatusMessage>
      <LocalizedLink class="button button--dark" :to="{ name: 'login' }">{{ t('auth.signIn') }}</LocalizedLink>
    </div>
    <div v-else-if="access === 'forbidden'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage>
    </div>
    <div v-else-if="access === 'error'" class="admin-dashboard__state">
      <StatusMessage variant="error">{{ error }}</StatusMessage>
      <button type="button" class="button button--outline" @click="loadCounts">{{ t('adminTools.refresh') }}</button>
    </div>
    <div v-else class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main">
        <header class="admin-invitations__heading">
          <p class="eyebrow">{{ t('adminDashboard.navMarketing') }}</p>
          <h1>{{ t('adminBroadcast.title') }}</h1>
          <p>{{ t('adminBroadcast.intro') }}</p>
        </header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <StatusMessage v-if="notice" variant="success">{{ notice }}</StatusMessage>

        <section class="admin-invitations__card">
          <header>
            <span class="admin-invitations__step">1</span><h2>{{ t('adminBroadcast.contentStep') }}</h2>
            <button type="button" class="admin-invitations__reset" :disabled="sending" @click="resetContent"><RotateCcw :size="14" aria-hidden="true" />{{ t('adminBroadcast.reset') }}</button>
          </header>
          <div class="admin-broadcast__fields">
            <label class="form-field">
              <span>{{ t('adminBroadcast.subject') }}</span>
              <input v-model="content.subject" type="text" :maxlength="SUBJECT_MAX" :disabled="sending" required />
            </label>
            <label class="form-field">
              <span>{{ t('adminBroadcast.heading') }}</span>
              <input v-model="content.heading" type="text" :maxlength="SUBJECT_MAX" :disabled="sending" required />
            </label>
          </div>
          <div class="admin-invitations__editor">
            <div class="admin-invitations__variables" role="group" :aria-label="t('adminBroadcast.variables')">
              <span>{{ t('adminBroadcast.insert') }}</span>
              <button v-for="name in BROADCAST_VARIABLES" :key="name" type="button" :disabled="sending" @click="insertVariable(name)" v-text="variableToken(name)"></button>
            </div>
            <label class="form-field">
              <span class="sr-only">{{ t('adminBroadcast.message') }}</span>
              <textarea ref="editor" v-model="content.message" rows="18" :maxlength="MESSAGE_MAX" :disabled="sending" aria-describedby="broadcast-message-help" />
            </label>
            <p id="broadcast-message-help" class="admin-invitations__counter" :class="{ 'is-error': unknownVariables.length }">
              <span v-if="unknownVariables.length">{{ t('adminBroadcast.unknownVariables', { names: unknownVariables.join(', ') }) }}</span>
              <span v-else>{{ t('adminBroadcast.variablesHint') }}</span>
              <span>{{ content.message.length }} / {{ MESSAGE_MAX }}</span>
            </p>
          </div>
          <div class="admin-broadcast__fields">
            <label class="form-field">
              <span>{{ t('adminBroadcast.buttonLabel') }}</span>
              <input v-model="content.buttonLabel" type="text" maxlength="80" :disabled="sending" required />
            </label>
            <label class="form-field">
              <span>{{ t('adminBroadcast.buttonUrl') }}</span>
              <input v-model="content.buttonUrl" type="text" maxlength="500" :disabled="sending" :aria-invalid="!urlValid" aria-describedby="broadcast-url-help" required />
              <small id="broadcast-url-help" :class="{ 'is-error': !urlValid }">{{ t('adminBroadcast.buttonUrlHint') }}</small>
            </label>
          </div>
          <details class="admin-invitations__preview admin-broadcast__preview" open>
            <summary>{{ t('adminBroadcast.preview') }}</summary>
            <div class="admin-broadcast__mail">
              <p class="admin-broadcast__subject"><strong>{{ t('adminBroadcast.subject') }}:</strong> {{ preview.subject }}</p>
              <h3>{{ preview.heading }}</h3>
              <p class="admin-broadcast__text">{{ preview.message }}</p>
              <span class="admin-broadcast__button">{{ preview.buttonLabel }}</span>
            </div>
          </details>
        </section>

        <section class="admin-invitations__card">
          <header><span class="admin-invitations__step">2</span><h2>{{ t('adminBroadcast.audienceStep') }}</h2></header>
          <fieldset class="admin-broadcast__audiences" :disabled="sending">
            <legend class="sr-only">{{ t('adminBroadcast.audienceStep') }}</legend>
            <label v-for="item in audiences" :key="item.value" class="admin-broadcast__audience" :class="{ 'is-active': audience === item.value }">
              <input v-model="audience" type="radio" name="broadcast-audience" :value="item.value" />
              <span><strong>{{ item.label }}</strong><small>{{ item.hint }}</small></span>
            </label>
          </fieldset>
          <SingleSelect v-if="audience === 'all' || audience === 'newsletter'" v-model="role" :label="t('adminBroadcast.role')" :options="roleOptions" :disabled="sending" />
          <MultiSelect
            v-if="audience === 'users'"
            :model-value="userIds"
            :options="userOptions"
            :selection-options="selectionOptions"
            :label="t('adminBroadcast.users')"
            :placeholder="t('adminBroadcast.usersPlaceholder')"
            :search-placeholder="t('adminBroadcast.searchUsers')"
            :no-results-label="t('adminBroadcast.noUsers')"
            :remove-label="t('account.remove')"
            :loading-label="t('adminDashboard.loading')"
            :load-more-label="t(users.error ? 'directoryLoading.retry' : 'directoryLoading.loadMore')"
            :loading="users.loading"
            :has-more="users.error || users.hasMore"
            :disabled="sending"
            show-images
            remote
            @update:model-value="selectUsers"
            @search="searchUsers"
            @load-more="loadUsers()"
          />
          <template v-if="audience === 'emails'">
            <TagInput
              v-model="emails"
              :label="t('adminBroadcast.emails')"
              :placeholder="t('adminBroadcast.emailsPlaceholder')"
              :remove-label="t('account.remove')"
              :limit-label="t('adminBroadcast.emailsLimit', { count: EMAILS_MAX })"
              :too-long-label="t('adminBroadcast.emailsInvalid', { emails: '' })"
              :max-tags="EMAILS_MAX"
              :max-length="254"
              :disabled="sending"
            />
            <p v-if="invalidEmails.length" class="admin-invitations__counter is-error">{{ t('adminBroadcast.emailsInvalid', { emails: invalidEmails.join(', ') }) }}</p>
          </template>
          <p class="admin-invitations__hint"><strong>{{ t('adminBroadcast.recipients', { count: recipientCount }) }}</strong></p>
        </section>

        <div class="admin-invitations__footer">
          <p>{{ t('adminBroadcast.footerHint') }}</p>
          <div class="admin-broadcast__actions">
            <button type="button" class="button button--outline" :disabled="!contentValid || testing || sending" @click="sendTest">
              <MailCheck :size="16" aria-hidden="true" />{{ t('adminBroadcast.sendTest') }}
            </button>
            <button type="button" class="button button--dark" :disabled="!canSend" @click="confirming = true">
              <Send :size="16" aria-hidden="true" />{{ t('adminBroadcast.send', { count: recipientCount }) }}
            </button>
          </div>
        </div>
      </main>
    </div>
    <ConfirmationModal
      :open="confirming"
      :title="t('adminBroadcast.confirmTitle', { count: recipientCount })"
      :message="t('adminBroadcast.confirmMessage', { count: recipientCount, subject: content.subject })"
      :confirm-label="t('adminBroadcast.send', { count: recipientCount })"
      :cancel-label="t('adminDashboard.cancelAction')"
      :loading="sending"
      @update:open="confirming = $event"
      @confirm="send"
    />
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminInvitationsView.scss"></style>
<style lang="scss" src="../scss/views/AdminBroadcastView.scss"></style>
