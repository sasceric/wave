<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, ArrowRight, Check, CircleHelp, LifeBuoy, Lightbulb, Mail, Paperclip, Send, Upload, X } from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import PhoneNumberField from '../components/shared/PhoneNumberField.vue'
import SuccessModal from '../components/shared/SuccessModal.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiRequest, formatDate } from '../lib/api'
import { formatInternationalPhoneNumber } from '../lib/phoneNumbers'
import { localizedRouteName } from '../routePaths'
import { ticketCategories, ticketKinds, ticketSubmissionKey, validTicketFiles } from '../lib/supportTicket'

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const form = reactive({ name: '', email: '', phone: '', kind: 'problem', category: '', title: '', description: '', trap: '' })
const phoneCountry = ref({ hr: 'HR', sr: 'RS', cnr: 'ME', sl: 'SI', en: 'GB' }[locale.value] || 'BA')
const files = ref([])
const step = ref(1)
const error = ref('')
const busy = ref(false)
const successOpen = ref(false)
const result = ref(null)
const tracked = ref(null)
const trackingLoading = ref(false)
const heading = ref(null)
const submissionKey = ref(ticketSubmissionKey())
let trackingVersion = 0
const landing = computed(() => route.meta.routeName === 'support')
const tracking = computed(() => route.meta.routeName === 'support-track')
const categoryOptions = computed(() => ticketCategories.map(value => ({ value, label: t(`support.categories.${value}`) })))
const kindIcons = { problem: LifeBuoy, question: CircleHelp, suggestion: Lightbulb }
const titles = computed(() => [t('support.createTitle'), t('support.detailsTitle'), t('support.reviewTitle')])
const intros = computed(() => [t('support.createIntro'), t('support.detailsIntro'), t('support.reviewIntro')])
const steps = computed(() => [t('support.basic'), t('support.details'), t('support.confirmation')])
const topics = computed(() => [
  { label: t('support.profileTopic'), category: 'account' },
  { label: t('support.campaignTopic'), category: 'campaigns' },
  { label: t('support.servicesTopic'), category: 'services' },
  { label: t('support.technicalTopic'), category: 'technical' },
])

function prefill() {
  form.name = currentUser.value?.name || currentUser.value?.profile?.displayName || currentUser.value?.profile?.name || ''
  form.email = currentUser.value?.email || ''
}
function reset() {
  Object.assign(form, { name: '', email: '', phone: '', kind: 'problem', category: '', title: '', description: '', trap: '' })
  prefill()
  files.value = []
  step.value = 1
  result.value = null
  error.value = ''
  submissionKey.value = ticketSubmissionKey()
  successOpen.value = false
}
async function start(kind = 'problem', category = '') {
  reset()
  form.kind = ticketKinds.includes(kind) ? kind : 'problem'
  form.category = ticketCategories.includes(category) ? category : ''
  await router.push({ name: localizedRouteName('support-create', locale.value) })
}
async function changeStep(value) {
  step.value = value
  error.value = ''
  await nextTick()
  heading.value?.focus()
}
function continueStep() {
  if (step.value === 1 && (!form.name.trim() || !form.email.trim() || !form.category)) {
    error.value = t('support.invalid')
    return
  }
  if (step.value === 2 && (form.title.trim().length < 3 || form.description.trim().length < 20)) {
    error.value = t('support.invalid')
    return
  }
  changeStep(step.value + 1)
}
function addFiles(incoming) {
  const next = [...files.value, ...Array.from(incoming)]
  if (!validTicketFiles(next)) {
    error.value = t('support.invalidFiles')
    return
  }
  files.value = next
  error.value = ''
}
function chooseFiles(event) {
  addFiles(event.target.files)
  event.target.value = ''
}
async function send() {
  if (busy.value || result.value) return
  busy.value = true
  error.value = ''
  const body = new FormData()
  for (const [key, value] of Object.entries(form)) body.append(key, key === 'phone' && value ? formatInternationalPhoneNumber(value, phoneCountry.value) || value : value.trim())
  body.append('submissionKey', submissionKey.value)
  for (const file of files.value) body.append('attachments[]', file)
  try {
    const response = await apiRequest('/support/tickets', { method: 'POST', body })
    result.value = response.data
    successOpen.value = true
  } catch (cause) {
    error.value = cause.message || t('support.saveFailed')
  } finally {
    busy.value = false
  }
}
async function trackCreated() {
  if (currentUser.value && result.value.id) {
    successOpen.value = false
    await router.push({ name: localizedRouteName('account-support', locale.value), query: { ticket: result.value.id } })
    return
  }
  const hash = new URL(result.value.trackingUrl, window.location.origin).hash
  successOpen.value = false
  await router.push({ name: localizedRouteName('support-track', locale.value), hash })
}
async function loadTracking(before = 0) {
  const version = ++trackingVersion
  if (!Number.isInteger(before) || before === 0) tracked.value = null
  error.value = ''
  if (!tracking.value) return
  const token = new URLSearchParams(route.hash.slice(1)).get('token') || ''
  if (!/^[a-f0-9]{64}$/.test(token)) {
    error.value = t('support.notFound')
    return
  }
  trackingLoading.value = true
  try {
    const response = await apiRequest('/support/tickets/track', { method: 'POST', body: { token, before: Number.isInteger(before) ? before : 0 } })
    if (version === trackingVersion) {
      if (Number.isInteger(before) && before > 0 && tracked.value) response.data.messages = [...response.data.messages, ...tracked.value.messages]
      tracked.value = response.data
    }
  } catch (cause) {
    if (version === trackingVersion) error.value = cause.message
  } finally {
    if (version === trackingVersion) trackingLoading.value = false
  }
}
watch(() => [route.meta.routeName, route.hash, locale.value], loadTracking)
onMounted(() => { prefill(); loadTracking() })
onBeforeUnmount(() => { trackingVersion++ })
</script>

<template>
  <main class="support-page page-width">
    <template v-if="landing">
      <section class="support-hero">
        <div class="support-hero__copy">
          <p class="eyebrow">{{ t('support.eyebrow') }}</p>
          <h1>{{ t('support.heading') }} <em>{{ t('support.headingAccent') }}</em></h1>
          <p>{{ t('support.intro') }}</p>
        </div>
        <div class="support-hero__art" aria-hidden="true"><img src="/images/home-faq-creator-480.webp" width="480" height="600" alt="" /></div>
      </section>
      <section class="support-choices" :aria-label="t('support.label')">
        <button v-for="kind in ticketKinds" :key="kind" type="button" class="support-choice" @click="start(kind)">
          <component :is="kindIcons[kind]" :size="36" stroke-width="1.6" aria-hidden="true" />
          <strong>{{ t(`support.${kind}`) }}</strong><span>{{ t(`support.${kind}Description`) }}</span>
        </button>
      </section>
      <section class="support-topics"><h2>{{ t('support.popular') }}</h2><div><button v-for="topic in topics" :key="topic.category" type="button" @click="start('question', topic.category)">{{ topic.label }}<ArrowRight :size="17" aria-hidden="true" /></button></div></section>
    </template>
    <template v-else-if="tracking">
      <LocalizedLink class="support-back" :to="{ name: 'support' }"><ArrowLeft :size="16" aria-hidden="true" />{{ t('support.label') }}</LocalizedLink>
      <header class="support-heading"><p class="eyebrow">{{ t('support.label') }}</p><h1>{{ t('support.trackingTitle') }}</h1><p>{{ t('support.trackingIntro') }}</p></header>
      <LoadingSkeleton v-if="trackingLoading" variant="form" :label="t('support.loading')" />
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <section v-if="tracked" class="support-tracking">
        <span class="support-tracking__icon"><Check :size="28" aria-hidden="true" /></span><h2>#{{ tracked.number }} · {{ tracked.title }}</h2>
        <dl><div><dt>{{ t('support.status') }}</dt><dd>{{ t(`support.statuses.${tracked.status}`) }}</dd></div><div><dt>{{ t('support.createdAt') }}</dt><dd>{{ formatDate(tracked.createdAt) }}</dd></div></dl>
        <p>{{ t('support.keepLink') }}</p>
        <button v-if="tracked.hasMore" type="button" class="button button--outline" :disabled="trackingLoading" @click="loadTracking(tracked.nextCursor)">{{ t('support.olderReplies') }}</button>
        <article v-for="message in tracked.messages" :key="message.id" class="support-tracking__reply"><strong>{{ message.name }}</strong><small>{{ formatDate(message.createdAt) }}</small><p>{{ message.body }}</p></article>
        <LocalizedLink v-if="tracked.accountTicketId" class="button button--outline" :to="{ name: 'account-support', query: { ticket: tracked.accountTicketId } }">{{ t('support.yourReports') }}<ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
        <LocalizedLink v-else class="button button--outline" :to="{ name: 'account-support' }">{{ t('support.yourReports') }}<ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
      </section>
      <button type="button" class="button button--dark" @click="start()">{{ t('support.newTicket') }}<ArrowRight :size="17" aria-hidden="true" /></button>
    </template>
    <template v-else>
      <div class="support-topbar"><LocalizedLink class="support-back" :to="{ name: 'support' }"><ArrowLeft :size="16" aria-hidden="true" />{{ t('support.back') }}</LocalizedLink>
        <ol class="support-steps" :aria-label="t('support.createTitle')"><li v-for="(label, index) in steps" :key="label" :class="{ 'is-active': step === index + 1, 'is-complete': step > index + 1 }" :aria-current="step === index + 1 ? 'step' : undefined"><span><Check v-if="step > index + 1" :size="14" aria-hidden="true" /><template v-else>{{ index + 1 }}</template></span>{{ label }}</li></ol>
      </div>
      <header class="support-heading support-heading--form"><div><p class="eyebrow">{{ t(`support.${form.kind}`) }}</p><h1 ref="heading" tabindex="-1">{{ titles[step - 1] }}</h1><p>{{ intros[step - 1] }}</p></div><span class="support-heading__icon"><Mail :size="42" stroke-width="1.4" aria-hidden="true" /><span>!</span></span></header>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <section v-if="result && !successOpen" class="support-tracking"><h2>{{ t('support.successTitle') }}</h2><p>{{ t('support.successMessage', { number: result.number }) }}</p><div class="support-actions"><button type="button" class="button button--dark" @click="trackCreated">{{ t('support.track') }}</button><button type="button" class="button button--outline" @click="start()">{{ t('support.newTicket') }}</button></div></section>
      <form v-else v-form-validation class="support-form" :aria-busy="busy" @submit.prevent="step === 3 ? send() : continueStep()">
        <div v-if="step === 1" class="support-form__basic">
          <label class="form-field"><span>{{ t('support.name') }}</span><input v-model="form.name" required minlength="2" maxlength="120" autocomplete="name" :placeholder="t('support.namePlaceholder')" /></label>
          <PhoneNumberField v-model="form.phone" v-model:country-code="phoneCountry" :optional="true" :label="t('support.phone')" placeholder="61 123 456" :country-label="t('auth.phoneCountry')" :country-placeholder="t('auth.selectCountry')" :country-search-placeholder="t('auth.searchPhoneCountry')" :no-countries-found-label="t('auth.noPhoneCountriesFound')" />
          <label class="form-field"><span>{{ t('support.email') }}</span><input v-model="form.email" required type="email" maxlength="180" autocomplete="email" :placeholder="t('support.emailPlaceholder')" /></label>
          <SingleSelect v-model="form.category" required :options="categoryOptions" :label="t('support.category')" :placeholder="t('support.chooseCategory')" />
        </div>
        <div v-else-if="step === 2" class="support-form__details">
          <div class="support-form__message"><label class="form-field"><span>{{ t('support.title') }}</span><input v-model="form.title" required minlength="3" maxlength="160" :placeholder="t('support.titlePlaceholder')" /></label>
            <label class="form-field"><span>{{ t('support.description') }}</span><textarea v-model="form.description" required minlength="20" maxlength="2000" rows="9" :placeholder="t('support.descriptionPlaceholder')"></textarea><small class="support-counter">{{ form.description.length }}/2000</small></label>
            <p class="support-privacy">{{ t('support.privacy') }}</p>
          </div>
          <aside class="support-upload"><Paperclip :size="24" aria-hidden="true" /><h2>{{ t('support.files') }}</h2><p>{{ t('support.filesIntro') }}</p>
            <label class="support-upload__drop" @dragover.prevent @drop.prevent="addFiles($event.dataTransfer.files)"><Upload :size="26" aria-hidden="true" /><strong>{{ t('support.upload') }}</strong><span>{{ t('support.drop') }}</span><input type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,application/pdf" :aria-label="t('support.files')" @change="chooseFiles" /></label>
            <small>{{ t('support.fileHint') }}</small><ul class="support-files"><li v-for="(file, index) in files" :key="index"><span>{{ file.name }} <small>{{ Math.ceil(file.size / 1024) }} KB</small></span><button type="button" :aria-label="t('support.removeFile', { name: file.name })" @click="files.splice(index, 1)"><X :size="16" aria-hidden="true" /></button></li></ul>
          </aside>
        </div>
        <div v-else class="support-review"><dl><div><dt>{{ t('support.name') }}</dt><dd>{{ form.name }}</dd></div><div><dt>{{ t('support.email') }}</dt><dd>{{ form.email }}</dd></div><div v-if="form.phone"><dt>{{ t('support.phone') }}</dt><dd>{{ formatInternationalPhoneNumber(form.phone, phoneCountry) || form.phone }}</dd></div><div><dt>{{ t('support.category') }}</dt><dd>{{ t(`support.categories.${form.category}`) }}</dd></div><div><dt>{{ t('support.kind') }}</dt><dd>{{ t(`support.${form.kind}`) }}</dd></div></dl><div><h2>{{ form.title }}</h2><p class="support-review__description">{{ form.description }}</p><ul v-if="files.length" class="support-files"><li v-for="(file, index) in files" :key="index"><Paperclip :size="16" aria-hidden="true" /><span>{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</span></li></ul></div></div>
        <input v-model="form.trap" type="text" class="support-trap" tabindex="-1" aria-hidden="true" autocomplete="off" />
        <footer class="support-actions"><LocalizedLink v-if="step === 1" class="button button--outline" :to="{ name: 'support' }">{{ t('support.cancel') }}</LocalizedLink><button v-else type="button" class="button button--outline" :disabled="busy" @click="changeStep(step - 1)"><ArrowLeft :size="16" aria-hidden="true" />{{ t('support.back') }}</button><button class="button button--dark" type="submit" :disabled="busy"><Send v-if="step === 3" :size="17" aria-hidden="true" />{{ busy ? t('support.sending') : step === 3 ? t('support.send') : t('support.next') }}<ArrowRight v-if="step < 3" :size="17" aria-hidden="true" /></button></footer>
      </form>
    </template>
    <SuccessModal v-model:open="successOpen" class="support-success" :title="t('support.successTitle')" :message="t('support.successMessage', { number: result?.number || '' })" :close-label="t('support.close')" show-close>
      <template #actions><div class="support-success__footer"><div><button type="button" class="button button--dark" @click="trackCreated">{{ t('support.track') }}<ArrowRight :size="17" aria-hidden="true" /></button><button type="button" class="button button--outline" @click="start()">{{ t('support.newTicket') }}</button></div><p><Mail :size="20" aria-hidden="true" />{{ result?.receiptSent ? t('support.receipt') : t('support.receiptFailed') }}</p></div></template>
    </SuccessModal>
  </main>
</template>

<style lang="scss" src="../scss/views/SupportView.scss"></style>
