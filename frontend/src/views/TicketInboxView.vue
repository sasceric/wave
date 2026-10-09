<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ArrowLeft, ArrowRight, LifeBuoy, Paperclip, Plus, RefreshCw, Search, Send, X } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiRequest, formatDate } from '../lib/api'
import { localizedRouteName } from '../routePaths'
import { ticketCategories, ticketSubmissionKey, validTicketFiles } from '../lib/supportTicket'

const route = useRoute()
const router = useRouter()
const { locale, t } = useI18n()
const admin = computed(() => route.meta.routeName === 'admin-support')
const scope = computed(() => admin.value ? 'admin' : 'me')
const base = computed(() => `/${scope.value}/support-tickets`)
const access = ref('loading')
const rows = ref([])
const counts = ref({})
const listRoot = ref(null)
const listBusy = ref(false)
const listError = ref('')
const cursor = ref(null)
const hasMore = ref(false)
const search = ref('')
const filter = ref('all')
const selected = ref(null)
const detailBusy = ref(false)
const detailError = ref('')
const messages = ref([])
const historyRoot = ref(null)
const historyBusy = ref(false)
const historyMore = ref(false)
const historyCursor = ref(null)
const historyError = ref('')
const body = ref('')
const files = ref([])
const internal = ref(false)
const sending = ref(false)
const replyError = ref('')
const saving = ref(false)
const saveError = ref('')
const staff = ref([])
const submissionKey = ref(ticketSubmissionKey())
const statuses = ['all', 'open', 'in_progress', 'on_hold', 'resolved']
const statusOptions = computed(() => statuses.slice(1).map(value => ({ value, label: t(`support.statuses.${value}`) })))
const priorityOptions = computed(() => ['low', 'normal', 'high'].map(value => ({ value, label: t(`support.priorities.${value}`) })))
const categoryOptions = computed(() => ticketCategories.map(value => ({ value, label: t(`support.categories.${value}`) })))
const staffOptions = computed(() => [{ value: '', label: t('support.unassigned') }, ...staff.value.map(user => ({ value: user.id, label: user.name }))])
let contextVersion = 0
let listVersion = 0
let detailVersion = 0
let searchTimer

function dateTime(value) {
  return `${formatDate(value)} · ${new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(new Date(value))}`
}
function initials(name) { return (name || 'W').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() }
function attachmentUrl(file) { return `${file.url}?locale=${encodeURIComponent(locale.value)}` }
async function loadList(reset = false) {
  if (listBusy.value && !reset) return
  const version = reset ? ++listVersion : listVersion
  if (reset) { rows.value = []; cursor.value = null; hasMore.value = false; counts.value = {} }
  listBusy.value = true
  listError.value = ''
  const params = new URLSearchParams({ limit: '30', status: filter.value, q: search.value.trim() })
  if (cursor.value) params.set('cursor', cursor.value)
  try {
    const response = await apiRequest(`${base.value}?${params}`)
    if (version !== listVersion) return
    const known = new Set(rows.value.map(row => row.id))
    rows.value.push(...response.data.filter(row => !known.has(row.id)))
    counts.value = response.meta.counts
    cursor.value = response.meta.nextCursor
    hasMore.value = response.meta.hasMore
    access.value = 'allowed'
  } catch (cause) {
    if (version !== listVersion) return
    listError.value = cause.message
    if ([401, 403].includes(cause.status)) { rows.value = []; selected.value = null; messages.value = []; access.value = cause.status === 401 ? 'anonymous' : 'forbidden' }
    else access.value = 'allowed'
  } finally { if (version === listVersion) listBusy.value = false }
}
async function selectTicket(id) {
  await router.push({ name: localizedRouteName(admin.value ? 'admin-support' : 'account-support', locale.value), query: { ticket: id } })
}
async function clearSelection() {
  await router.push({ name: localizedRouteName(admin.value ? 'admin-support' : 'account-support', locale.value) })
}
async function loadDetail() {
  const version = ++detailVersion
  selected.value = null
  messages.value = []
  historyBusy.value = false
  historyMore.value = false
  historyCursor.value = null
  detailError.value = ''; historyError.value = ''; replyError.value = ''; saveError.value = ''
  body.value = ''; files.value = []; internal.value = false; sending.value = false; saving.value = false
  submissionKey.value = ticketSubmissionKey()
  const id = String(route.query.ticket || '')
  if (!/^\d+$/.test(id)) { detailBusy.value = false; return }
  detailBusy.value = true
  try {
    const path = `${base.value}/${id}`
    const [detail, history] = await Promise.all([apiRequest(path), apiRequest(`${path}/messages?limit=30`)])
    if (version !== detailVersion) return
    selected.value = detail.data
    messages.value = history.data
    historyMore.value = history.meta.hasMore
    historyCursor.value = history.meta.nextCursor
    await nextTick()
    if (historyRoot.value) historyRoot.value.scrollTop = historyRoot.value.scrollHeight
    if (detail.data.throughNotificationId) {
      // Only clear alerts that existed when this history was opened; later alerts remain unread.
      await apiRequest(`${path}/read`, { method: 'POST', body: { throughNotificationId: detail.data.throughNotificationId } })
      if (version !== detailVersion) return
      const row = rows.value.find(row => row.id === detail.data.id)
      if (row) row.unreadCount = 0
      window.dispatchEvent(new CustomEvent('wave:support-read'))
    }
  } catch (cause) { if (version === detailVersion) detailError.value = cause.message }
  finally {
    if (version === detailVersion) {
      detailBusy.value = false
      await nextTick()
      if (historyRoot.value) historyRoot.value.scrollTop = historyRoot.value.scrollHeight
    }
  }
}
async function loadOlder() {
  if (!selected.value || !historyMore.value || historyBusy.value) return
  const version = detailVersion
  const height = historyRoot.value?.scrollHeight || 0
  const top = historyRoot.value?.scrollTop || 0
  historyBusy.value = true
  historyError.value = ''
  try {
    const response = await apiRequest(`${base.value}/${selected.value.id}/messages?limit=30&before=${historyCursor.value}`)
    if (version !== detailVersion) return
    const known = new Set(messages.value.map(message => message.id))
    messages.value.unshift(...response.data.filter(message => !known.has(message.id)))
    historyMore.value = response.meta.hasMore
    historyCursor.value = response.meta.nextCursor
    await nextTick()
    if (historyRoot.value) historyRoot.value.scrollTop = top + historyRoot.value.scrollHeight - height
  } catch (cause) { if (version === detailVersion) historyError.value = cause.message }
  finally { if (version === detailVersion) historyBusy.value = false }
}
function scrollHistory() {
  if ((historyRoot.value?.scrollTop ?? 999) < 100 && !detailBusy.value && !historyError.value) loadOlder()
}
function addFiles(event) {
  const additions = Array.from(event.target.files || [])
  const combined = [...files.value, ...additions]
  if (!validTicketFiles(combined)) replyError.value = t('support.invalidFiles')
  else { files.value = combined; replyError.value = '' }
  event.target.value = ''
}
async function sendReply() {
  if (sending.value || !selected.value || !body.value.trim()) return
  const version = detailVersion
  const id = selected.value.id
  sending.value = true
  replyError.value = ''
  const form = new FormData()
  form.append('body', body.value.trim())
  form.append('internal', internal.value ? '1' : '0')
  form.append('submissionKey', submissionKey.value)
  files.value.forEach(file => form.append('attachments[]', file, file.name))
  try {
    const response = await apiRequest(`${base.value}/${id}/messages`, { method: 'POST', body: form })
    if (version !== detailVersion) return
    if (!messages.value.some(message => message.id === response.data.id)) messages.value.push(response.data)
    selected.value.status = response.status
    if (!response.data.internal) {
      selected.value.preview = response.data.body
      selected.value.updatedAt = response.data.createdAt
      const row = rows.value.find(row => row.id === id)
      if (row) Object.assign(row, { status: response.status, preview: response.data.body, updatedAt: response.data.createdAt })
    }
    body.value = ''; files.value = []; submissionKey.value = ticketSubmissionKey()
    await nextTick()
    if (historyRoot.value) historyRoot.value.scrollTop = historyRoot.value.scrollHeight
    loadList(true)
  } catch (cause) { if (version === detailVersion) replyError.value = cause.message }
  finally { if (version === detailVersion) sending.value = false }
}
async function updateTicket(field, value) {
  if (saving.value || !admin.value || !selected.value) return
  const version = detailVersion
  saving.value = true
  saveError.value = ''
  try {
    const response = await apiRequest(`${base.value}/${selected.value.id}`, { method: 'PATCH', body: { [field]: field === 'assignedToId' && value === '' ? null : value } })
    if (version !== detailVersion) return
    selected.value = response.data
    loadList(true)
  } catch (cause) { if (version === detailVersion) saveError.value = cause.message }
  finally { if (version === detailVersion) saving.value = false }
}
async function initialize() {
  ++listVersion; ++detailVersion
  rows.value = []; selected.value = null; messages.value = []; staff.value = []
  access.value = 'loading'
  const version = ++contextVersion
  await loadList(true)
  if (access.value !== 'allowed' || version !== contextVersion) return
  loadDetail()
  if (admin.value) {
    try { const response = await apiRequest(`${base.value}/staff`); if (version === contextVersion) staff.value = response.data }
    catch (cause) { if (version === contextVersion) saveError.value = cause.message }
  }
}
watch(() => route.query.ticket, loadDetail)
watch([() => currentUser.value?.id, scope, locale], initialize)
watch(filter, () => loadList(true))
watch(search, () => { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(() => loadList(true), 300) })
onMounted(initialize)
onBeforeUnmount(() => { ++contextVersion; ++listVersion; ++detailVersion; window.clearTimeout(searchTimer) })
</script>

<template>
  <section class="page-width ticket-inbox" :class="{ 'ticket-inbox--selected': selected || detailBusy || detailError }">
    <LocalizedLink class="ticket-inbox__back" :to="{ name: admin ? 'admin' : 'account' }"><ArrowLeft :size="15" aria-hidden="true" />{{ t('support.back') }}</LocalizedLink>
    <header class="ticket-inbox__heading"><div><h1>{{ t(admin ? 'support.adminTitle' : 'support.myTickets') }}</h1><p>{{ t('support.inboxIntro') }}</p></div><LocalizedLink class="button button--dark" :to="{ name: 'support-create' }"><Plus :size="17" aria-hidden="true" />{{ t('support.newTicket') }}</LocalizedLink></header>
    <StatusMessage v-if="access === 'anonymous'">{{ t('support.signInTickets') }} <LocalizedLink :to="{ name: 'account', query: { mode: 'login', returnTo: route.fullPath } }">{{ t('auth.signIn') }}</LocalizedLink></StatusMessage>
    <StatusMessage v-else-if="access === 'forbidden'" variant="error">{{ t('adminDashboard.noAccess') }}</StatusMessage>
    <div v-else class="ticket-inbox__grid">
      <section class="ticket-list" :aria-label="t(admin ? 'support.allTickets' : 'support.myTickets')">
        <div class="ticket-list__search"><Search :size="17" aria-hidden="true" /><input v-model="search" type="search" :placeholder="t('support.searchTickets')" :aria-label="t('support.searchTickets')" /></div>
        <div class="ticket-list__tabs" :aria-label="t('support.status')"><button v-for="value in statuses" :key="value" type="button" :aria-pressed="filter === value" :class="{ 'is-active': filter === value }" @click="filter = value">{{ t(value === 'all' ? 'support.all' : `support.statuses.${value}`) }}<span>{{ counts[value] || 0 }}</span></button></div>
        <div ref="listRoot" class="ticket-list__scroll">
          <button v-for="ticket in rows" :key="ticket.id" class="ticket-list__row" :class="{ 'is-selected': ticket.id === selected?.id }" type="button" :aria-pressed="ticket.id === selected?.id" @click="selectTicket(ticket.id)">
            <span class="ticket-avatar"><img v-if="ticket.avatarUrl" :src="ticket.avatarUrl" alt="" loading="lazy" /><span v-else>{{ initials(ticket.name) }}</span></span>
            <span class="ticket-list__text"><strong>{{ ticket.title }}</strong><span>{{ ticket.preview }}</span><small>#{{ ticket.number }} · {{ formatDate(ticket.updatedAt) }}</small></span>
            <span class="ticket-list__meta"><span class="ticket-status" :class="`ticket-status--${ticket.status}`">{{ t(`support.statuses.${ticket.status}`) }}</span><b v-if="ticket.unreadCount" class="ticket-unread">{{ ticket.unreadCount }}</b></span>
          </button>
          <p v-if="!rows.length && !listBusy && !listError" class="ticket-list__empty">{{ t('support.empty') }}</p>
          <DirectoryLoadMore :loading="listBusy" :has-more="hasMore" :error="listError" :count="rows.length" :root="listRoot" @load="loadList()" />
        </div>
      </section>
      <section class="ticket-thread" :aria-label="selected?.title || t('support.chooseTicket')" :aria-busy="detailBusy">
        <p v-if="detailBusy" class="ticket-thread__empty" role="status">{{ t('support.loading') }}</p>
        <StatusMessage v-else-if="detailError" variant="error">{{ detailError }}<button type="button" @click="loadDetail">{{ t('directoryLoading.retry') }}</button><button type="button" @click="clearSelection">{{ t('support.back') }}</button></StatusMessage>
        <template v-else-if="selected">
          <header class="ticket-thread__heading"><button class="ticket-thread__mobile-back" type="button" :aria-label="t('support.back')" @click="clearSelection"><ArrowLeft :size="18" /></button><div><h2>{{ selected.title }}</h2><p>#{{ selected.number }}</p></div><span class="ticket-status" :class="`ticket-status--${selected.status}`">{{ t(`support.statuses.${selected.status}`) }}</span><button class="ticket-icon" type="button" :aria-label="t('adminTools.refresh')" :title="t('adminTools.refresh')" @click="loadDetail"><RefreshCw :size="17" aria-hidden="true" /></button></header>
          <div ref="historyRoot" class="ticket-thread__history" @scroll.passive="scrollHistory">
            <StatusMessage v-if="historyError" variant="error">{{ historyError }}</StatusMessage>
            <button v-if="historyMore" class="ticket-thread__older" type="button" :disabled="historyBusy" @click="loadOlder">{{ t(historyBusy ? 'support.loading' : 'support.olderReplies') }}</button>
            <article v-if="!historyMore" class="ticket-message"><header><span class="ticket-avatar">{{ initials(selected.name) }}</span><strong>{{ selected.name }}</strong><time :datetime="selected.createdAt">{{ dateTime(selected.createdAt) }}</time></header><p>{{ selected.description }}</p><ul v-if="selected.attachments.length" class="ticket-files"><li v-for="file in selected.attachments" :key="file.url"><a :href="attachmentUrl(file)" download><Paperclip :size="15" aria-hidden="true" />{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</a></li></ul></article>
            <article v-for="message in messages" :key="message.id" class="ticket-message" :class="{ 'ticket-message--staff': message.staff, 'ticket-message--internal': message.internal }"><header><span class="ticket-avatar"><img v-if="message.avatarUrl" :src="message.avatarUrl" alt="" loading="lazy" /><span v-else>{{ initials(message.senderName) }}</span></span><strong>{{ message.senderName }}<small v-if="message.staff">{{ t(message.internal ? 'support.internalNote' : 'support.staff') }}</small></strong><time :datetime="message.createdAt">{{ dateTime(message.createdAt) }}</time></header><p>{{ message.body }}</p><ul v-if="message.attachments.length" class="ticket-files"><li v-for="file in message.attachments" :key="file.url"><a :href="attachmentUrl(file)" download><Paperclip :size="15" aria-hidden="true" />{{ file.name }} · {{ Math.ceil(file.size / 1024) }} KB</a></li></ul></article>
          </div>
          <form class="ticket-composer" @submit.prevent="sendReply">
            <div v-if="admin" class="ticket-composer__tabs"><button type="button" :aria-pressed="!internal" :class="{ 'is-active': !internal }" @click="internal = false">{{ t('support.reply') }}</button><button type="button" :aria-pressed="internal" :class="{ 'is-active': internal }" @click="internal = true">{{ t('support.internalNote') }}</button></div>
            <label class="sr-only" for="ticket-reply">{{ t(internal ? 'support.internalNote' : 'support.reply') }}</label><textarea id="ticket-reply" v-model="body" maxlength="4000" required :disabled="sending" :placeholder="t('support.replyPlaceholder')" />
            <StatusMessage v-if="replyError" variant="error">{{ replyError }}</StatusMessage>
            <ul v-if="files.length" class="ticket-files"><li v-for="(file, index) in files" :key="index">{{ file.name }}<button type="button" :disabled="sending" :aria-label="`${t('support.removeFile')}: ${file.name}`" @click="files.splice(index, 1)"><X :size="14" aria-hidden="true" /></button></li></ul>
            <div class="ticket-composer__actions"><label class="ticket-icon ticket-attach" :title="t('support.attachments')"><Paperclip :size="18" aria-hidden="true" /><span class="sr-only">{{ t('support.attachments') }}</span><input type="file" multiple accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,application/pdf" :disabled="sending" @change="addFiles" /></label><small>{{ body.length }} / 4000</small><button type="submit" class="button button--dark" :disabled="sending || !body.trim()"><Send :size="16" aria-hidden="true" />{{ t(sending ? 'support.sendingReply' : 'support.sendReply') }}</button></div>
            <p class="ticket-composer__notice">{{ t('support.replyNotice') }}</p>
          </form>
        </template>
        <div v-else class="ticket-thread__empty"><LifeBuoy :size="30" aria-hidden="true" /><h2>{{ t('support.chooseTicket') }}</h2><p>{{ t('support.chooseTicketHint') }}</p></div>
      </section>
      <aside v-if="selected" class="ticket-info"><h2>{{ t('support.ticketInfo') }}</h2><p class="ticket-info__number">#{{ selected.number }}</p><StatusMessage v-if="saveError" variant="error">{{ saveError }}</StatusMessage>
        <template v-if="admin"><SingleSelect :model-value="selected.status" :options="statusOptions" :label="t('support.status')" :disabled="saving" @update:model-value="updateTicket('status', $event)" /><SingleSelect :model-value="selected.priority" :options="priorityOptions" :label="t('support.priority')" :disabled="saving" @update:model-value="updateTicket('priority', $event)" /><SingleSelect :model-value="selected.category" :options="categoryOptions" :label="t('support.category')" :disabled="saving" @update:model-value="updateTicket('category', $event)" /><SingleSelect :model-value="selected.assignedToId || ''" :options="staffOptions" :label="t('support.assignedTo')" :disabled="saving" @update:model-value="updateTicket('assignedToId', $event)" /></template>
        <dl><div v-if="!admin"><dt>{{ t('support.category') }}</dt><dd>{{ t(`support.categories.${selected.category}`) }}</dd></div><div><dt>{{ t('support.createdAt') }}</dt><dd>{{ dateTime(selected.createdAt) }}</dd></div><div><dt>{{ t('support.lastUpdate') }}</dt><dd>{{ dateTime(selected.updatedAt) }}</dd></div></dl>
        <div class="ticket-info__user"><h3>{{ t('support.user') }}</h3><strong>{{ selected.owner?.name || selected.name }}</strong><a :href="`mailto:${selected.owner?.email || selected.email}`">{{ selected.owner?.email || selected.email }}</a><p v-if="selected.phone">{{ selected.phone }}</p><LocalizedLink v-if="selected.owner?.slug" :to="{ name: selected.owner.creator ? 'creator-profile' : 'company-profile', params: { slug: selected.owner.slug } }">{{ t('support.publicProfile') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink></div>
      </aside>
    </div>
  </section>
</template>

<style lang="scss" src="../scss/views/TicketInboxView.scss"></style>
