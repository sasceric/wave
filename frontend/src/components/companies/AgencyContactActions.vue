<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Mail, Megaphone } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { apiGet, apiRequest } from '../../lib/api'
const props = defineProps({ company: { type: Object, required: true } })
const { t } = useI18n()
const route = useRoute()
const mode = ref('')
const message = ref('')
const packageId = ref(null)
const selectedCampaign = ref('')
const campaigns = ref([])
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const sent = ref(false)
let revision = 0
const own = computed(() => currentUser.value?.id === props.company.ownerId)
const canContact = computed(() => currentUser.value?.accountType === 'company' && currentUser.value.approved && currentUser.value.emailVerified && currentUser.value.profileComplete !== false && !own.value)
const campaignOptions = computed(() => campaigns.value.map(row => ({ value: row.slug, label: row.title })))
async function open(action, selectedPackage = null) {
  const token = ++revision
  mode.value = action
  message.value = ''
  packageId.value = selectedPackage
  error.value = ''
  sent.value = false
  if (action !== 'invite' || !canContact.value) return
  loading.value = true
  try {
    const result = await apiGet('/me/campaigns')
    if (token !== revision) return
    campaigns.value = result.data.filter(row => row.status === 'open' && new Date(`${row.closesAt.slice(0, 10)}T23:59:59`) >= new Date() && !row.invitedCreatorIds?.includes(props.company.providerId) && !row.applicantCreatorIds?.includes(props.company.providerId))
    selectedCampaign.value = campaigns.value[0]?.slug || ''
  } catch (cause) { if (token === revision) error.value = cause.message }
  finally { if (token === revision) loading.value = false }
}
async function send() {
  if (busy.value || sent.value || !canContact.value || (mode.value === 'invite' && !selectedCampaign.value)) return
  busy.value = true
  error.value = ''
  try {
    if (mode.value === 'invite') await apiRequest(`/company/campaigns/${encodeURIComponent(selectedCampaign.value)}/invitations`, { method: 'POST', body: { agencyId: props.company.id, message: message.value.trim() } })
    else await apiRequest(`/companies/${encodeURIComponent(props.company.slug)}/inquiries`, { method: 'POST', body: { message: message.value.trim(), packageIds: packageId.value ? [packageId.value] : [], servicePackage: !packageId.value, currency: 'BAM' } })
    sent.value = true
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
watch(() => props.company.id, () => { ++revision; mode.value = ''; loading.value = false })
defineExpose({ openRequest: (id = null) => open('request', id) })
</script>
<template>
  <div v-if="!own" class="agency-contact-actions">
    <button class="button button--light" type="button" @click="open('request')"><Mail :size="17" />{{ t('creatorProfile.sendRequest') }}</button>
    <button class="button button--dark" type="button" @click="open('invite')"><Megaphone :size="17" />{{ t('creatorProfile.inviteAction') }}</button>
  </div>
  <FormModal :open="Boolean(mode)" :title="t(mode === 'invite' ? 'creatorProfile.inviteAction' : 'creatorProfile.sendRequest')" :busy="busy" @update:open="!$event && (mode = '')" @submit="send">
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-if="sent">{{ t(mode === 'invite' ? 'campaignChat.invitationSent' : 'account.actionSaved') }}</StatusMessage>
    <template v-else-if="!canContact">
      <p>{{ t('agency.contactHint') }}</p>
      <LocalizedLink class="button button--dark" :to="{ name: currentUser ? 'account' : 'register', query: { accountType: 'company', returnTo: route.path } }">{{ t(currentUser ? 'account.title' : 'auth.register') }}<ArrowRight :size="16" /></LocalizedLink>
    </template>
    <p v-else-if="loading" role="status">{{ t('app.loading') }}</p>
    <template v-else>
      <template v-if="mode === 'invite'">
        <SingleSelect v-if="campaigns.length" v-model="selectedCampaign" :label="t('campaignChat.campaign')" :options="campaignOptions" required />
        <p v-else>{{ t('agency.noCampaigns') }}</p>
      </template>
      <label v-if="mode !== 'invite' || campaigns.length" class="form-field"><span>{{ t('account.writeMessage') }}</span><textarea v-model.trim="message" required :minlength="mode === 'invite' ? 1 : 10" maxlength="2000"></textarea></label>
    </template>
    <template #actions>
      <button class="button button--outline" type="button" :disabled="busy" @click="mode = ''">{{ t('account.richTextCancel') }}</button>
      <button v-if="canContact && !sent && !loading && (mode !== 'invite' || campaigns.length)" class="button button--dark" type="submit" :disabled="busy">{{ t('account.sendMessage') }}<ArrowRight :size="16" /></button>
    </template>
  </FormModal>
</template>
