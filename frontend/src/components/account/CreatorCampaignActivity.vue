<script setup>
import CampaignHiringProgress from '../campaigns/CampaignHiringProgress.vue'
import AdminRowActions from '../admin/AdminRowActions.vue'
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { ArrowRight, Bookmark, CalendarDays, Camera, CirclePlay, ExternalLink, Eye, MapPin, Megaphone, MessageCircle, Music2, Wallet, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import CardImage from '../shared/CardImage.vue'
import RichTextContent from '../shared/RichTextContent.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import DirectoryPagination from '../shared/DirectoryPagination.vue'
import DirectorySearch from '../shared/DirectorySearch.vue'
import { useCampaignBookmarks } from '../../composables/useCampaignBookmarks'
import { formatDate, formatMoney } from '../../lib/api'
import { campaignPlace, CAMPAIGN_PLACEHOLDER, CREATOR_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({
  items: { type: Array, default: () => [] },
  kind: { type: String, default: 'offers', validator: (value) => ['offers', 'applications', 'inquiries', 'invitations'].includes(value) },
  canRespond: { type: Boolean, default: false },
  responding: { type: Boolean, default: false },
})
const emit = defineEmits(['respond'])
const { t, locale } = useI18n()
const { isCampaignBookmarked, toggleCampaignBookmark } = useCampaignBookmarks()
const dialog = ref(null)
const page = ref(1)
const heading = ref(null)
watch(page, async () => {
  await nextTick()
  heading.value?.focus({ preventScroll: true })
  heading.value?.scrollIntoView({ block: 'start' })
})
const isOffers = computed(() => props.kind === 'offers')
const isInquiries = computed(() => props.kind === 'inquiries')
const isInvitations = computed(() => props.kind === 'invitations')
const titleKey = computed(() => isInvitations.value ? 'account.campaignInvitations' : isInquiries.value ? 'account.directRequests' : isOffers.value ? 'account.myOffers' : 'account.myApplications')
const introKey = computed(() => isInvitations.value ? 'account.invitationsIntro' : isInquiries.value ? 'account.inquiriesIntro' : isOffers.value ? 'account.offersIntro' : 'account.applicationsIntro')
const searchKey = computed(() => isInvitations.value ? 'account.searchCompanyCampaigns' : isInquiries.value ? 'account.searchInquiries' : isOffers.value ? 'account.searchOffers' : 'account.searchApplications')
function counterpart(item) {
  if (!isInquiries.value) return item.campaign.company
  return item.role === 'company' ? { ...item.creator, name: item.creator.displayName, logoUrl: item.creator.avatarUrl || CREATOR_PLACEHOLDER } : item.company
}
function itemTitle(item) {
  return isInquiries.value ? item.packageTitle || t('creatorProfile.generalRequest') : item.campaign.title
}
function itemSummary(item) {
  return isInquiries.value ? item.message : item.campaign.summary
}
function itemBudget(item) {
  if (!isInquiries.value) return `${formatMoney(item.campaign.budgetMin, item.campaign.currency)} – ${formatMoney(item.campaign.budgetMax, item.campaign.currency)}`
  const amount = item.proposedAmount ?? item.listedPrice
  return amount === null || amount === undefined ? t('creatorProfile.priceByAgreement') : formatMoney(amount, item.proposedAmount !== null && item.proposedAmount !== undefined ? item.currency : item.listedPriceCurrency)
}
function hasChat(item) {
  return isInquiries.value ? item.canChat : Boolean(item.conversationId)
}
function packageLabel(selection) {
  return selection.type === 'service' ? t('creatorProfile.servicePackage') : selection.type === 'other' ? t('creatorProfile.somethingElse') : selection.title
}
const query = ref('')
const statusFilter = ref('all')
function statusGroup(item) {
  if (isInvitations.value) {
    if (item.status === 'accepted') return 'accepted'
    if (item.status === 'declined') return 'rejected'
    return invitationExpired(item) ? 'expired' : 'pending'
  }
  if (item.campaign?.status === 'closed') return 'closed'
  if (['rejected', 'offer_declined'].includes(item.status)) return 'rejected'
  if (item.status === 'accepted') return 'accepted'
  return 'pending'
}
const statusFilters = computed(() => [
  { value: 'all', label: t('account.activityAll'), count: props.items.length },
  { value: 'pending', label: t(isOffers.value ? 'account.offerAwaitingResponse' : 'account.pending'), count: props.items.filter(item => statusGroup(item) === 'pending').length },
  { value: 'accepted', label: t('account.accepted'), count: props.items.filter(item => statusGroup(item) === 'accepted').length },
  { value: 'rejected', label: t('account.rejected'), count: props.items.filter(item => statusGroup(item) === 'rejected').length },
  { value: isInvitations.value ? 'expired' : 'closed', label: t(isInvitations.value ? 'account.invitationExpired' : 'campaignChat.closed'), count: props.items.filter(item => statusGroup(item) === (isInvitations.value ? 'expired' : 'closed')).length },
].filter(filter => !isInquiries.value || filter.value !== 'closed'))
const filteredItems = computed(() => {
  const search = query.value.trim().toLocaleLowerCase(locale.value)
  return props.items.filter(item => (statusFilter.value === 'all' || statusGroup(item) === statusFilter.value)
    && (!search || [counterpart(item).name, itemTitle(item), itemSummary(item), ...(isInvitations.value ? [areas(item.campaign), ...item.campaign.channels] : [])].some(value => (value || '').toLocaleLowerCase(locale.value).includes(search))))
})
const pageItems = computed(() => filteredItems.value.slice((page.value - 1) * 10, page.value * 10))
watch([query, statusFilter, () => props.kind], () => { page.value = 1 })
watch(() => filteredItems.value.length, (count) => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(count / 10)))
})
const selectedItemId = ref(null)
const selectedItem = computed(() => props.items.find((offer) => offer.id === selectedItemId.value) ?? null)
const campaign = computed(() => selectedItem.value?.campaign)
const bookmarkBusy = ref(false)
const bookmarkError = ref('')
const id = useId()
let previousOverflow = null
let disposed = false

function platformIcon(platform) {
  return { Instagram: Camera, TikTok: Music2, YouTube: CirclePlay }[platform] || Megaphone
}

function chatRoute(offer) {
  return { name: 'messages', query: isInquiries.value ? { inquiry: offer.id } : { conversation: offer.conversationId } }
}

function statusLabel(offer) {
  if (isInvitations.value) return t(statusGroup(offer) === 'expired' ? 'account.invitationExpired' : `account.${statusGroup(offer)}`)
  if (offer.campaign?.status === 'closed') return t('campaignChat.closed')
  const key = offer.status === 'offer_declined' ? 'offerDeclined' : offer.status
  return t(isOffers.value && key === 'pending' ? 'account.offerAwaitingResponse' : `account.${key}`)
}

function invitationExpired(item) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return item.campaign.status !== 'open' || new Date(item.campaign.closesAt).getTime() < today.getTime()
}

function areas(campaign) {
  return campaign.categoryLabels?.length ? campaign.categoryLabels.join(', ')
    : campaign.categories?.length ? campaign.categories.join(', ') : campaign.categoryLabel || campaign.category || ''
}

function deadlineLabel(campaign) {
  const deadline = new Date(campaign.closesAt)
  deadline.setHours(0, 0, 0, 0)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const days = Math.round((deadline - today) / 86400000)
  if (!Number.isFinite(days)) return ''
  return days < 0 ? t('account.campaignDeadlinePassed') : t('account.campaignDeadlineInDays', { count: days })
}

function closeCampaign() {
  dialog.value?.close()
  selectedItemId.value = null
  if (previousOverflow !== null) {
    document.body.style.overflow = previousOverflow
    previousOverflow = null
  }
}

async function openCampaign(offer) {
  selectedItemId.value = offer.id
  bookmarkError.value = ''
  await nextTick()
  if (disposed || !selectedItem.value || !dialog.value || dialog.value.open) return

  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  dialog.value.showModal()
}

async function toggleBookmark() {
  if (bookmarkBusy.value || !campaign.value) return
  bookmarkBusy.value = true
  bookmarkError.value = ''
  try {
    await toggleCampaignBookmark(campaign.value)
  } catch (cause) {
    bookmarkError.value = cause.message
  } finally {
    bookmarkBusy.value = false
  }
}

watch(selectedItem, (offer) => {
  if (!offer && dialog.value?.open) closeCampaign()
})
onBeforeUnmount(() => {
  disposed = true
  closeCampaign()
})
</script>

<template>
  <section :id="`account-panel-${kind}`" class="creator-offers account-activity-panel" :class="{ 'campaign-invitations': isInvitations }" aria-labelledby="creator-offers-title">
    <header class="creator-offers__heading">
      <h1 id="creator-offers-title" ref="heading" tabindex="-1">{{ t(titleKey) }} <span v-if="!isInvitations">{{ items.length }}</span></h1>
      <p>{{ t(introKey) }}</p>
    </header>
    <StatusMessage v-if="!items.length" variant="empty">{{ t(isInvitations ? 'account.noCampaignInvitations' : isInquiries ? 'account.noDirectRequests' : isOffers ? 'account.noOffers' : 'account.noApplications') }}</StatusMessage>
    <template v-else>
      <div class="creator-offers__toolbar">
        <div class="creator-offers__filters" role="group" :aria-label="t('account.activityStatus')">
          <button v-for="filter in statusFilters" :key="filter.value" type="button" :class="{ 'is-active': statusFilter === filter.value }" :aria-pressed="statusFilter === filter.value" @click="statusFilter = filter.value">
            <i :class="`activity-dot activity-dot--${filter.value}`" aria-hidden="true"></i>{{ filter.label }}<span>{{ filter.count }}</span>
          </button>
        </div>
        <DirectorySearch v-model="query" :label="t(searchKey)" :placeholder="t(searchKey)" />
      </div>
      <StatusMessage v-if="!filteredItems.length" variant="empty">{{ t('account.activityNoResults') }}</StatusMessage>
      <div v-else class="creator-offers__table-wrap">
        <table class="creator-offers__table">
          <caption class="sr-only">{{ t(titleKey) }}</caption>
          <thead><tr>
            <th scope="col">{{ t(isInvitations ? 'adminDashboard.campaign' : isInquiries ? 'account.activityInquiry' : 'account.activityCampaign') }}</th>
            <th v-if="isInvitations" scope="col">{{ t('campaignChat.company') }}</th>
            <th v-if="isInvitations" scope="col">{{ t('auth.category') }}</th>
            <th scope="col">{{ t('campaignChat.budget') }}</th>
            <th v-if="!isInquiries" scope="col">{{ t(isInvitations ? 'account.closesAt' : 'account.offerDeadline') }}</th>
            <th scope="col">{{ t(isInvitations ? 'account.hiredCreators' : isOffers || isInquiries ? 'account.activityReceived' : 'account.activitySubmitted') }}</th>
            <th scope="col">{{ t('account.activityStatus') }}</th>
            <th scope="col" class="creator-offers__action-cell">{{ t('account.activityActions') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="offer in pageItems" :key="offer.id">
              <td v-if="isInvitations" class="campaign-invitations__campaign">
                <div class="creator-offers__campaign-identity">
                  <CardImage :image="offer.campaign.coverImage" :src="offer.campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER" alt="" sizes="92px" />
                  <div><button type="button" @click="openCampaign(offer)">{{ offer.campaign.title }}</button><p>{{ offer.campaign.summary }}</p>
                    <div class="offer-campaign-meta"><span v-for="channel in offer.campaign.channels" :key="channel"><component :is="platformIcon(channel)" :size="15" aria-hidden="true" />{{ channel }}</span></div>
                  </div>
                </div>
              </td>
              <td v-else class="creator-offers__campaign">
                <div class="creator-offers__campaign-identity">
                  <CardImage :image="counterpart(offer).logoImage || offer.campaign?.coverImage" :src="counterpart(offer).logoUrl || offer.campaign?.coverImageUrl || (isInquiries ? '' : CAMPAIGN_PLACEHOLDER)" alt="" sizes="56px" />
                  <div><strong>{{ counterpart(offer).name }}</strong><button type="button" @click="openCampaign(offer)">{{ itemTitle(offer) }}</button><p>{{ itemSummary(offer) }}</p>
                  <CampaignHiringProgress v-if="offer.campaign" :campaign="offer.campaign" /></div>
                </div>
              </td>
              <td v-if="isInvitations"><span class="creator-offers__mobile-label">{{ t('campaignChat.company') }}</span>{{ counterpart(offer).name }}</td>
              <td v-if="isInvitations"><span class="creator-offers__mobile-label">{{ t('auth.category') }}</span>{{ areas(offer.campaign) }}</td>
              <td class="creator-offers__budget"><span class="creator-offers__mobile-label">{{ t('campaignDetail.budget') }}</span><strong>{{ itemBudget(offer) }}</strong></td>
              <td v-if="!isInquiries"><span class="creator-offers__mobile-label">{{ t(isInvitations ? 'account.closesAt' : 'account.offerDeadline') }}</span><span v-if="offer.campaign.closesAt" class="creator-offers__deadline"><CalendarDays :size="17" aria-hidden="true" /><time :datetime="offer.campaign.closesAt">{{ formatDate(offer.campaign.closesAt) }}</time></span><small v-if="isInvitations" class="campaign-invitations__deadline-hint">{{ deadlineLabel(offer.campaign) }}</small></td>
              <td v-if="isInvitations"><span class="creator-offers__mobile-label">{{ t('account.hiredCreators') }}</span><CampaignHiringProgress :campaign="offer.campaign" /></td>
              <td v-else><span class="creator-offers__mobile-label">{{ t(isOffers || isInquiries ? 'account.activityReceived' : 'account.activitySubmitted') }}</span><time v-if="offer.createdAt" :datetime="offer.createdAt">{{ formatDate(offer.createdAt) }}</time></td>
              <td><span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(offer)}`"><i :class="`activity-dot activity-dot--${statusGroup(offer)}`" aria-hidden="true"></i>{{ statusLabel(offer) }}</span></td>
              <td v-if="isInvitations" class="creator-offers__action-cell"><div class="creator-offers__actions campaign-invitations__actions">
                <div class="campaign-invitations__primary-actions">
                  <template v-if="statusGroup(offer) === 'pending'">
                    <button class="button button--dark" type="button" :disabled="!canRespond || responding" @click="emit('respond', offer, 'accept')">{{ t('account.acceptInvitation') }}</button>
                    <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', offer, 'decline')">{{ t('account.declineInvitation') }}</button>
                  </template>
                  <LocalizedLink v-else-if="offer.status === 'accepted' && hasChat(offer)" class="button button--dark" :to="chatRoute(offer)">{{ t('account.openChat') }}</LocalizedLink>
                  <button v-else class="button button--outline" type="button" @click="openCampaign(offer)">{{ t('account.invitationViewDetails') }}</button>
                </div>
                <AdminRowActions :label="`${t('account.activityActions')}: ${offer.campaign.title}`">
                  <button class="admin-row-actions__item" role="menuitem" type="button" @click="openCampaign(offer)"><Eye :size="16" aria-hidden="true" />{{ t('account.invitationViewDetails') }}</button>
                  <LocalizedLink class="admin-row-actions__item" role="menuitem" :to="{ name: 'campaign-detail', params: { slug: offer.campaign.slug } }"><ExternalLink :size="16" aria-hidden="true" />{{ t('campaignCard.viewBrief') }}</LocalizedLink>
                </AdminRowActions>
              </div></td>
              <td v-else class="creator-offers__action-cell"><div class="creator-offers__actions">
                <LocalizedLink v-if="hasChat(offer)" class="button button--outline" :to="chatRoute(offer)"><MessageCircle :size="16" aria-hidden="true" />{{ t('account.activityChat') }}</LocalizedLink>
                <button class="button button--dark" type="button" :aria-label="`${t(isInquiries ? 'account.activityView' : 'campaignCard.viewBrief')}: ${itemTitle(offer)}`" @click="openCampaign(offer)">{{ t('account.activityView') }}<ArrowRight :size="16" aria-hidden="true" /></button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <DirectoryPagination v-if="filteredItems.length" v-model:page="page" :page-size="10" :show-page-size="false" :show-single-page="true" :total="filteredItems.length" :status-label="t(isInvitations ? 'account.invitationsShown' : 'account.activityShown', { shown: pageItems.length, total: filteredItems.length })" />
    </template>
    <dialog ref="dialog" class="offer-campaign-modal" aria-modal="true" :aria-labelledby="`${id}-title`" @cancel.prevent="closeCampaign" @close="closeCampaign" @click.self="closeCampaign">
      <div v-if="selectedItem" class="offer-campaign-modal__layout">
        <header v-if="campaign" class="offer-campaign-modal__header">
          <div class="offer-campaign-modal__cover"><CardImage :image="campaign.coverImage" :src="campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER" alt="" sizes="620px" /></div>
          <div class="offer-campaign-modal__branding">
            <span class="offer-campaign-modal__logo"><CardImage v-if="campaign.company.logoImage || campaign.company.logoUrl" :image="campaign.company.logoImage" :src="campaign.company.logoUrl" :alt="campaign.company.name" sizes="64px" /><span v-else>{{ campaign.company.name.slice(0, 1) }}</span></span>
            <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`"><i :class="`activity-dot activity-dot--${statusGroup(selectedItem)}`" aria-hidden="true"></i>{{ statusLabel(selectedItem) }}</span>
          </div>
          <p class="creator-offer__company">{{ campaign.company.name }}</p>
          <h2 :id="`${id}-title`">{{ campaign.title }}</h2>
          <p class="creator-offer__summary">{{ campaign.summary }}</p>
          <div class="offer-campaign-meta">
            <span v-for="channel in campaign.channels" :key="channel"><component :is="platformIcon(channel)" :size="16" aria-hidden="true" />{{ channel }}</span>
            <span v-if="campaignPlace(campaign, locale)"><MapPin :size="16" aria-hidden="true" />{{ campaignPlace(campaign, locale) }}</span>
          </div>
          <button class="offer-campaign-modal__close" type="button" :aria-label="t('account.closeCampaignModal')" @click="closeCampaign"><X :size="22" aria-hidden="true" /></button>
        </header>
        <header v-else class="offer-campaign-modal__header offer-campaign-modal__header--inquiry">
          <p class="creator-offer__company">{{ counterpart(selectedItem).name }}</p>
          <h2 :id="`${id}-title`">{{ itemTitle(selectedItem) }}</h2>
          <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`">{{ statusLabel(selectedItem) }}</span>
          <button class="offer-campaign-modal__close" type="button" :aria-label="t('creatorProfile.closeRequest')" @click="closeCampaign"><X :size="22" aria-hidden="true" /></button>
        </header>
        <div v-if="campaign" class="offer-campaign-modal__content">
          <StatusMessage v-if="campaign.status === 'finished'" variant="info">{{ t('account.campaignFinishedNotice') }}</StatusMessage>
          <CampaignHiringProgress :campaign="campaign" />
          <section>
            <h3>{{ t('account.offerCampaignAbout') }}</h3>
            <RichTextContent :html="campaign.description" />
          </section>
          <dl class="offer-campaign-modal__facts">
            <div><Wallet :size="23" aria-hidden="true" /><dt>{{ t('campaignDetail.budget') }}</dt><dd>{{ formatMoney(campaign.budgetMin, campaign.currency) }} – {{ formatMoney(campaign.budgetMax, campaign.currency) }}</dd></div>
            <div v-if="campaign.closesAt"><CalendarDays :size="23" aria-hidden="true" /><dt>{{ t('account.offerDeadline') }}</dt><dd>{{ formatDate(campaign.closesAt) }}</dd></div>
            <div v-if="campaignPlace(campaign, locale)"><MapPin :size="23" aria-hidden="true" /><dt>{{ t('campaignDetail.location') }}</dt><dd>{{ campaignPlace(campaign, locale) }}</dd></div>
            <div v-if="campaign.deliverables?.length"><Camera :size="23" aria-hidden="true" /><dt>{{ t('account.deliverables') }}</dt><dd><ul><li v-for="(deliverable, index) in campaign.deliverables" :key="index">{{ deliverable }}</li></ul></dd></div>
          </dl>
          <section v-if="isOffers" class="offer-campaign-modal__offer">
            <h3>{{ t('account.offerDetails') }}</h3>
            <strong>{{ formatMoney(selectedItem.amount, campaign.currency) }}</strong>
            <p v-if="selectedItem.message">{{ selectedItem.message }}</p>
            <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`">{{ statusLabel(selectedItem) }}</span>
            <div v-if="selectedItem.status === 'pending' && campaign.status !== 'finished'" class="button-row">
              <button class="button button--dark" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'accept')">{{ t('account.accept') }}</button>
              <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'reject')">{{ t('account.reject') }}</button>
            </div>
          </section>
          <section v-else class="offer-campaign-modal__offer">
            <h3>{{ t(isInvitations ? 'campaignChat.invitationMessage' : 'account.applicationMessage') }}</h3>
            <p>{{ selectedItem.message }}</p>
            <span class="creator-offer__status">{{ statusLabel(selectedItem) }}</span>
            <div v-if="isInvitations && statusGroup(selectedItem) === 'pending'" class="button-row">
              <button class="button button--dark" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'accept')">{{ t('account.acceptInvitation') }}</button>
              <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'decline')">{{ t('account.declineInvitation') }}</button>
            </div>
          </section>
          <StatusMessage v-if="bookmarkError" variant="error">{{ bookmarkError }}</StatusMessage>
        </div>
        <div v-else class="offer-campaign-modal__content">
          <h3>{{ t('account.directRequests') }}</h3>
          <p class="offer-campaign-modal__inquiry-message">{{ selectedItem.message }}</p>
          <dl class="offer-campaign-modal__facts">
            <div><Wallet :size="23" aria-hidden="true" /><dt>{{ t('account.proposedPrice') }}</dt><dd>{{ itemBudget(selectedItem) }}</dd></div>
            <div v-if="selectedItem.createdAt"><CalendarDays :size="23" aria-hidden="true" /><dt>{{ t('account.activityReceived') }}</dt><dd>{{ formatDate(selectedItem.createdAt) }}</dd></div>
          </dl>
          <section v-if="selectedItem.selectedPackages?.length || selectedItem.packageTitle">
            <h3>{{ t('account.packages') }}</h3>
            <ul v-if="selectedItem.selectedPackages?.length"><li v-for="(selection, index) in selectedItem.selectedPackages" :key="index">{{ packageLabel(selection) }}</li></ul>
            <p v-else>{{ selectedItem.packageTitle }}</p>
          </section>
          <div v-if="selectedItem.role === 'creator' && selectedItem.status === 'pending'" class="button-row">
            <button class="button button--dark" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'accept')">{{ t('account.accept') }}</button>
            <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'reject')">{{ t('account.reject') }}</button>
          </div>
        </div>
        <footer v-if="campaign || hasChat(selectedItem)" class="offer-campaign-modal__footer">
          <button v-if="campaign" class="button button--outline" type="button" :disabled="bookmarkBusy || !canRespond" :aria-pressed="isCampaignBookmarked(campaign.slug)" @click="toggleBookmark"><Bookmark :size="18" aria-hidden="true" />{{ t(isCampaignBookmarked(campaign.slug) ? 'campaignCard.removeBookmark' : 'campaignCard.addBookmark') }}</button>
          <LocalizedLink v-if="hasChat(selectedItem)" class="button button--dark" :to="chatRoute(selectedItem)" @click="closeCampaign"><MessageCircle :size="18" aria-hidden="true" />{{ t('account.openChat') }}</LocalizedLink>
        </footer>
      </div>
    </dialog>
  </section>
</template>

<style lang="scss" src="../../scss/components/account/CreatorCampaignActivity.scss"></style>
