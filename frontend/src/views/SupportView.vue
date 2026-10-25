<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, ArrowRight, BookOpen, Check, ChevronRight, CircleAlert, CircleHelp, LifeBuoy, Lightbulb, Mail, Paperclip, Send, Ticket, Upload, X } from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import PhoneNumberField from '../components/shared/PhoneNumberField.vue'
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
    <nav class="support-page__breadcrumb" :aria-label="t('creatorProfile.breadcrumbs')">
      <LocalizedLink to="/">{{ t('app.homeTab') }}</LocalizedLink>
      <ChevronRight :size="14" aria-hidden="true" />
      <LocalizedLink v-if="!landing" :to="{ name: 'support' }">{{ t('support.label') }}</LocalizedLink>
      <span v-else aria-current="page">{{ t('support.label') }}</span>
      <template v-if="!landing"><ChevronRight :size="14" aria-hidden="true" /><span aria-current="page">{{ tracking ? t('support.trackingTitle') : t(`support.${form.kind}`) }}</span></template>
    </nav>

    <template v-if="landing">
      <section class="support-hero">
        <div class="support-hero__copy">
          <p class="eyebrow">{{ t('support.eyebrow') }}</p>
          <h1>{{ t('support.heading') }} <em>{{ t('support.headingAccent') }}</em></h1>
          <p class="support-hero__intro">{{ t('support.intro') }}</p>
        </div>
        <div class="support-hero__art" aria-hidden="true">
          <img src="/images/home-faq-creator-1050.webp" srcset="/images/home-faq-creator-1050.webp 1050w, /images/home-faq-creator-1400.webp 1400w" sizes="(max-width: 1050px) 0px, 380px" width="1050" height="1312" alt="" fetchpriority="high" />
        </div>
      </section>

      <section class="support-section" aria-labelledby="support-choices-title">
        <header class="support-section__head"><h2 id="support-choices-title">{{ t('support.chooseTitle') }}</h2><p>{{ t('support.chooseIntro') }}</p></header>
        <div class="support-choices">
          <button v-for="kind in ticketKinds" :key="kind" type="button" class="support-choice" @click="start(kind)">
            <span class="support-icon"><component :is="kindIcons[kind]" :size="22" aria-hidden="true" /></span>
            <strong>{{ t(`support.${kind}`) }}</strong>
            <span>{{ t(`support.${kind}Description`) }}</span>
            <span class="support-choice__link">{{ t('support.startAction') }} <ArrowRight :size="15" aria-hidden="true" /></span>
          </button>
        </div>
      </section>

      <section class="support-section" aria-labelledby="support-topics-title">
        <header class="support-section__head"><h2 id="support-topics-title">{{ t('support.popular') }}</h2></header>
        <div class="support-topics">
          <button v-for="topic in topics" :key="topic.category" type="button" @click="start('question', topic.category)">{{ topic.label }}<ArrowRight :size="15" aria-hidden="true" /></button>
        </div>
      </section>

      <div class="support-help">
        <section class="support-help__card">
          <span class="support-icon"><BookOpen :size="22" aria-hidden="true" /></span>
          <h3>{{ t('support.guidesTitle') }}</h3>
          <p>{{ t('support.guidesText') }}</p>
          <LocalizedLink class="support-link" :to="{ name: 'seo-guides' }">{{ t('support.guidesAction') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
        </section>
        <section class="support-help__card">
          <span class="support-icon"><Ticket :size="22" aria-hidden="true" /></span>
          <h3>{{ t('support.existingTitle') }}</h3>
          <p>{{ t('support.existingText') }}</p>
          <LocalizedLink class="support-link" :to="{ name: 'account-support' }">{{ t('support.myTickets') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
        </section>
      </div>
    </template>

    <template v-else-if="tracking">
      <header class="support-heading"><p class="eyebrow">{{ t('support.label') }}</p><h1>{{ t('support.trackingTitle') }}</h1><p>{{ t('support.trackingIntro') }}</p></header>
      <LoadingSkeleton v-if="trackingLoading" variant="form" :label="t('support.loading')" />
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <section v-if="tracked" class="support-card support-tracking">
        <div class="support-tracking__head">
          <span class="support-icon support-icon--ok"><Check :size="22" aria-hidden="true" /></span>
          <h2>#{{ tracked.number }} · {{ tracked.title }}</h2>
        </div>
        <dl class="support-review"><div><dt>{{ t('support.status') }}</dt><dd>{{ t(`support.statuses.${tracked.status}`) }}</dd></div><div><dt>{{ t('support.createdAt') }}</dt><dd>{{ formatDate(tracked.createdAt) }}</dd></div></dl>
        <StatusMessage>{{ t('support.keepLink') }}</StatusMessage>
        <button v-if="tracked.hasMore" type="button" class="button button--tonal" :disabled="trackingLoading" @click="loadTracking(tracked.nextCursor)">{{ t('support.olderReplies') }}</button>
        <article v-for="message in tracked.messages" :key="message.id" class="support-tracking__reply"><strong>{{ message.name }}</strong><small>{{ formatDate(message.createdAt) }}</small><p>{{ message.body }}</p></article>
        <LocalizedLink v-if="tracked.accountTicketId" class="button button--tonal" :to="{ name: 'account-support', query: { ticket: tracked.accountTicketId } }">{{ t('support.yourReports') }}<ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
        <LocalizedLink v-else class="button button--tonal" :to="{ name: 'account-support' }">{{ t('support.yourReports') }}<ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
      </section>
      <div><button type="button" class="button button--dark" @click="start()">{{ t('support.newTicket') }}<ArrowRight :size="16" aria-hidden="true" /></button></div>
    </template>

    <template v-else>
      <header class="support-heading"><p class="eyebrow">{{ t(`support.${form.kind}`) }}</p><h1>{{ t('support.formHeading') }}</h1></header>
      <div class="support-ticket">
        <div class="support-ticket__main">
          <section v-if="result" class="support-card support-sent" role="status">
            <span class="support-icon support-icon--ok support-icon--large"><Check :size="30" aria-hidden="true" /></span>
            <h2>{{ t('support.successTitle') }}</h2>
            <span class="support-sent__number">#{{ result.number }}</span>
            <p>{{ t('support.successMessage', { number: result.number }) }}</p>
            <StatusMessage :variant="result.receiptSent ? 'success' : 'info'">{{ result.receiptSent ? t('support.receipt') : t('support.receiptFailed') }}</StatusMessage>
            <div class="support-actions support-actions--center">
              <button type="button" class="button button--dark" @click="trackCreated">{{ t('support.track') }}<ArrowRight :size="16" aria-hidden="true" /></button>
              <button type="button" class="button button--tonal" @click="start()">{{ t('support.newTicket') }}</button>
            </div>
          </section>
          <template v-else>
            <ol class="support-steps" :aria-label="t('support.createTitle')">
              <li v-for="(label, index) in steps" :key="label" :class="{ 'is-active': step === index + 1, 'is-complete': step > index + 1 }" :aria-current="step === index + 1 ? 'step' : undefined"><i aria-hidden="true"></i>{{ index + 1 }}. {{ label }}</li>
            </ol>
            <form v-form-validation class="support-card support-form" :aria-busy="busy" @submit.prevent="step === 3 ? send() : continueStep()">
              <header class="support-form__head"><h2 ref="heading" tabindex="-1">{{ titles[step - 1] }}</h2><p>{{ intros[step - 1] }}</p></header>
              <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
              <div v-if="step === 1" class="support-form__basic">
                <div class="support-kinds" role="group" :aria-label="t('support.kind')">
                  <button v-for="kind in ticketKinds" :key="kind" type="button" class="support-kind" :aria-pressed="form.kind === kind" @click="form.kind = kind"><component :is="kindIcons[kind]" :size="18" aria-hidden="true" />{{ t(`support.${kind}`) }}</button>
                </div>
                <div class="support-grid">
                  <label class="form-field"><span>{{ t('support.name') }}</span><input v-model="form.name" required minlength="2" maxlength="120" autocomplete="name" :placeholder="t('support.namePlaceholder')" /></label>
                  <label class="form-field"><span>{{ t('support.email') }}</span><input v-model="form.email" required type="email" maxlength="180" autocomplete="email" :placeholder="t('support.emailPlaceholder')" /></label>
                  <PhoneNumberField v-model="form.phone" v-model:country-code="phoneCountry" :optional="true" :label="t('support.phone')" placeholder="61 123 456" :country-label="t('auth.phoneCountry')" :country-placeholder="t('auth.selectCountry')" :country-search-placeholder="t('auth.searchPhoneCountry')" :no-countries-found-label="t('auth.noPhoneCountriesFound')" />
                  <SingleSelect v-model="form.category" required :options="categoryOptions" :label="t('support.category')" :placeholder="t('support.chooseCategory')" />
                </div>
              </div>
              <div v-else-if="step === 2" class="support-form__details">
                <label class="form-field"><span>{{ t('support.title') }}</span><input v-model="form.title" required minlength="3" maxlength="160" :placeholder="t('support.titlePlaceholder')" /></label>
                <label class="form-field"><span>{{ t('support.description') }}</span><textarea v-model="form.description" required minlength="20" maxlength="2000" rows="8" :placeholder="t('support.descriptionPlaceholder')"></textarea><small class="support-counter">{{ form.description.length }}/2000</small></label>
                <div class="support-upload">
                  <label class="support-upload__drop" @dragover.prevent @drop.prevent="addFiles($event.dataTransfer.files)"><Upload :size="22" aria-hidden="true" /><strong>{{ t('support.upload') }}</strong><span>{{ t('support.drop') }}</span><input type="file" multiple accept="image/png,image/jpeg,image/gif,image/webp,video/mp4,application/pdf" :aria-label="t('support.files')" @change="chooseFiles" /></label>
                  <small>{{ t('support.fileHint') }}</small>
                  <ul v-if="files.length" class="support-files"><li v-for="(file, index) in files" :key="index"><Paperclip :size="16" aria-hidden="true" /><span>{{ file.name }} <small>{{ Math.ceil(file.size / 1024) }} KB</small></span><button type="button" :aria-label="t('support.removeFile', { name: file.name })" @click="files.splice(index, 1)"><X :size="16" aria-hidden="true" /></button></li></ul>
                </div>
                <p class="support-privacy"><CircleAlert :size="16" aria-hidden="true" />{{ t('support.privacy') }}</p>
              </div>
              <div v-else class="support-review-step">
                <dl class="support-review"><div><dt>{{ t('support.kind') }}</dt><dd>{{ t(`support.${form.kind}`) }}</dd></div><div><dt>{{ t('support.name') }}</dt><dd>{{ form.name }}</dd></div><div><dt>{{ t('support.email') }}</dt><dd>{{ form.email }}</dd></div><div v-if="form.phone"><dt>{{ t('support.phone') }}</dt><dd>{{ formatInternationalPhoneNumber(form.phone, phoneCountry) || form.phone }}</dd></div><div><dt>{{ t('support.category') }}</dt><dd>{{ t(`support.categories.${form.category}`) }}</dd></div><div><dt>{{ t('support.title') }}</dt><dd>{{ form.title }}</dd></div><div><dt>{{ t('support.description') }}</dt><dd class="support-review__description">{{ form.description }}</dd></div></dl>
                <ul v-if="files.length" class="support-files"><li v-for="(file, index) in files" :key="index"><Paperclip :size="16" aria-hidden="true" /><span>{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</span></li></ul>
              </div>
              <input v-model="form.trap" type="text" class="support-trap" tabindex="-1" aria-hidden="true" autocomplete="off" />
              <footer class="support-actions">
                <LocalizedLink v-if="step === 1" class="button button--tonal" :to="{ name: 'support' }">{{ t('support.cancel') }}</LocalizedLink>
                <button v-else type="button" class="button button--tonal" :disabled="busy" @click="changeStep(step - 1)"><ArrowLeft :size="16" aria-hidden="true" />{{ t('support.back') }}</button>
                <button class="button button--dark" type="submit" :disabled="busy"><Send v-if="step === 3" :size="16" aria-hidden="true" />{{ busy ? t('support.sending') : step === 3 ? t('support.send') : t('support.next') }}<ArrowRight v-if="step < 3" :size="16" aria-hidden="true" /></button>
              </footer>
            </form>
          </template>
        </div>
        <aside class="support-ticket__aside">
          <section class="support-help__card">
            <span class="support-icon"><Mail :size="22" aria-hidden="true" /></span>
            <h3>{{ t('support.replyTitle') }}</h3>
            <p>{{ t('support.replyText') }}</p>
          </section>
          <section class="support-help__card">
            <span class="support-icon"><BookOpen :size="22" aria-hidden="true" /></span>
            <h3>{{ t('support.guidesTitle') }}</h3>
            <p>{{ t('support.guidesText') }}</p>
            <LocalizedLink class="support-link" :to="{ name: 'seo-guides' }">{{ t('support.guidesAction') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
          </section>
        </aside>
      </div>
    </template>
  </main>
</template>

<style lang="scss" src="../scss/views/SupportView.scss"></style>
