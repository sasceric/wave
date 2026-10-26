<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RotateCcw, Send } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AdminCreatorPicker from '../components/admin/AdminCreatorPicker.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'
import { INVITATION_VARIABLES, defaultInvitationMessage, personaliseInvitation } from '../lib/invitationMessage'

const MESSAGE_MAX = 2000
const { locale, t } = useI18n()
const access = ref('loading')
const campaigns = ref([])
const campaignId = ref(null)
const message = ref(defaultInvitationMessage(locale.value))
const creatorIds = ref([])
const all = ref(false)
const available = ref(0)
const confirming = ref(false)
const sending = ref(false)
const error = ref('')
const notice = ref('')
const editor = ref(null)
const picker = ref(null)

const campaign = computed(() => campaigns.value.find((item) => item.id === campaignId.value) ?? null)
const campaignOptions = computed(() => campaigns.value.map((item) => ({
  value: item.id,
  label: `${item.title} · ${item.companyName}${item.invited ? ` · ${t('adminInvitations.invitedCount', { count: item.invited })}` : ''}`,
})))
const recipientCount = computed(() => (all.value ? available.value : creatorIds.value.length))
const messageLength = computed(() => message.value.trim().length)
const unknownVariables = computed(() => [...message.value.matchAll(/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g)]
  .map((match) => match[1])
  .filter((name) => !INVITATION_VARIABLES.includes(name)))
const messageValid = computed(() => messageLength.value >= 10 && messageLength.value <= MESSAGE_MAX && !unknownVariables.value.length)
const canSend = computed(() => Boolean(campaign.value) && recipientCount.value > 0 && messageValid.value && !sending.value)
const preview = computed(() => personaliseInvitation(message.value, {
  name: t('adminInvitations.previewName'),
  companyName: campaign.value?.companyName || 'Wave',
}))

async function loadCampaigns() {
  try {
    const { data } = await apiGet('/admin/campaign-invitations')
    campaigns.value = data
    if (!data.some((item) => item.id === campaignId.value)) campaignId.value = data[0]?.id ?? null
    access.value = 'allowed'
  } catch (cause) {
    if (cause.status === 401) access.value = 'anonymous'
    else if (cause.status === 403) access.value = 'forbidden'
    else { error.value = cause.message || t('adminInvitations.loadError'); access.value = 'error' }
  }
}

function variableToken(name) {
  return `{{${name}}}`
}

async function insertVariable(name) {
  const field = editor.value
  const token = variableToken(name)
  if (!field) { message.value += token; return }
  const start = field.selectionStart ?? message.value.length
  const end = field.selectionEnd ?? start
  message.value = `${message.value.slice(0, start)}${token}${message.value.slice(end)}`
  await nextTick()
  field.focus()
  field.setSelectionRange(start + token.length, start + token.length)
}

function resetMessage() {
  message.value = defaultInvitationMessage(locale.value)
}

async function send() {
  if (!canSend.value) return
  sending.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest(`/admin/campaign-invitations/${campaignId.value}`, {
      method: 'POST',
      body: all.value ? { message: message.value, all: true } : { message: message.value, creatorIds: creatorIds.value },
    })
    notice.value = data.skipped
      ? t('adminInvitations.sentWithSkipped', { count: data.invited, skipped: data.skipped })
      : t('adminInvitations.sent', { count: data.invited })
    confirming.value = false
    creatorIds.value = []
    all.value = false
    picker.value?.refresh()
    loadCampaigns()
  } catch (cause) {
    error.value = cause.message || t('adminInvitations.sendError')
    confirming.value = false
  } finally {
    sending.value = false
  }
}

watch(() => currentUser.value?.id, () => { access.value = 'loading'; loadCampaigns() })
onMounted(loadCampaigns)
</script>

<template>
  <AdminPage class="admin-invitations">
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
      <button type="button" class="button button--outline" @click="loadCampaigns">{{ t('adminTools.refresh') }}</button>
    </div>
    <div v-else class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="true" />
      <main class="admin-dashboard__main">
        <header class="admin-invitations__heading">
          <p class="eyebrow">{{ t('adminDashboard.navMarketing') }}</p>
          <h1>{{ t('adminInvitations.title') }}</h1>
          <p>{{ t('adminInvitations.intro') }}</p>
        </header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <StatusMessage v-if="notice" variant="success">{{ notice }}</StatusMessage>

        <section v-if="!campaigns.length" class="admin-invitations__card admin-invitations__empty">
          <h2>{{ t('adminInvitations.noCampaignsTitle') }}</h2>
          <p>{{ t('adminInvitations.noCampaigns') }}</p>
          <LocalizedLink class="button button--dark" :to="{ name: 'account-campaign-create' }">{{ t('adminInvitations.createCampaign') }}</LocalizedLink>
        </section>

        <template v-else>
          <section class="admin-invitations__card">
            <header><span class="admin-invitations__step">1</span><h2>{{ t('adminInvitations.campaignStep') }}</h2></header>
            <SingleSelect v-model="campaignId" :label="t('adminInvitations.campaign')" :options="campaignOptions" :disabled="sending" />
            <p v-if="campaign" class="admin-invitations__hint">
              {{ campaign.voluntary ? t('adminInvitations.voluntaryCampaign') : t('adminInvitations.paidCampaign') }}
              · <LocalizedLink :to="{ name: 'campaign-detail', params: { slug: campaign.slug } }" target="_blank">{{ t('adminInvitations.viewCampaign') }}</LocalizedLink>
            </p>
          </section>

          <section class="admin-invitations__card">
            <header>
              <span class="admin-invitations__step">2</span><h2>{{ t('adminInvitations.messageStep') }}</h2>
              <button type="button" class="admin-invitations__reset" :disabled="sending" @click="resetMessage"><RotateCcw :size="14" aria-hidden="true" />{{ t('adminInvitations.resetMessage') }}</button>
            </header>
            <div class="admin-invitations__editor">
              <div class="admin-invitations__variables" role="group" :aria-label="t('adminInvitations.variables')">
                <span>{{ t('adminInvitations.insert') }}</span>
                <button v-for="name in INVITATION_VARIABLES" :key="name" type="button" :disabled="sending" @click="insertVariable(name)" v-text="variableToken(name)"></button>
              </div>
              <label class="form-field">
                <span class="sr-only">{{ t('adminInvitations.message') }}</span>
                <textarea ref="editor" v-model="message" rows="16" :maxlength="MESSAGE_MAX + 200" :disabled="sending" :aria-describedby="'invitation-message-help'" />
              </label>
              <p id="invitation-message-help" class="admin-invitations__counter" :class="{ 'is-error': !messageValid && messageLength > 0 }">
                <span v-if="unknownVariables.length">{{ t('adminInvitations.unknownVariables', { names: unknownVariables.join(', ') }) }}</span>
                <span v-else>{{ t('adminInvitations.variablesHint') }}</span>
                <span>{{ messageLength }} / {{ MESSAGE_MAX }}</span>
              </p>
            </div>
            <details class="admin-invitations__preview">
              <summary>{{ t('adminInvitations.preview') }}</summary>
              <p>{{ preview }}</p>
            </details>
          </section>

          <section class="admin-invitations__card">
            <header><span class="admin-invitations__step">3</span><h2>{{ t('adminInvitations.creatorsStep') }}</h2></header>
            <AdminCreatorPicker
              v-if="campaignId"
              ref="picker"
              v-model="creatorIds"
              v-model:all="all"
              :campaign-id="campaignId"
              :disabled="sending"
              @available="available = $event"
            />
          </section>

          <div class="admin-invitations__footer">
            <p>{{ t('adminInvitations.footerHint') }}</p>
            <button type="button" class="button button--dark" :disabled="!canSend" @click="confirming = true">
              <Send :size="16" aria-hidden="true" />{{ t('adminInvitations.send', { count: recipientCount }) }}
            </button>
          </div>
        </template>
      </main>
    </div>
    <ConfirmationModal
      :open="confirming"
      :title="t('adminInvitations.confirmTitle', { count: recipientCount })"
      :message="t('adminInvitations.confirmMessage', { count: recipientCount, campaign: campaign?.title ?? '' })"
      :confirm-label="t('adminInvitations.send', { count: recipientCount })"
      :cancel-label="t('adminDashboard.cancelAction')"
      :loading="sending"
      @update:open="confirming = $event"
      @confirm="send"
    />
  </AdminPage>
</template>

<style lang="scss" src="../scss/views/AdminInvitationsView.scss"></style>
