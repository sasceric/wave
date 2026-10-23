<script setup>
import CampaignCompensation from '../campaigns/CampaignCompensation.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { campaignCompensationLabel } from '../../lib/campaignCompensation'
import { campaignActivityStage } from '../../lib/campaignActivity'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import CampaignCancellationAction from './CampaignCancellationAction.vue'
import ReviewAction from '../reviews/ReviewAction.vue'
import CampaignHiringProgress from '../campaigns/CampaignHiringProgress.vue'
import AdminRowActions from '../admin/AdminRowActions.vue'
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { ArrowRight, Ban, Check, Star, Bookmark, CalendarDays, Camera, CirclePlay, ExternalLink, Eye, MapPin, Megaphone, MessageCircle, Music2, Wallet, X } from '@lucide/vue'
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
  kind: { type: String, default: 'offers', validator: (value) => ['activity', 'offers', 'applications', 'inquiries', 'invitations'].includes(value) },
  canRespond: { type: Boolean, default: false },
  initialQuery: { type: String, default: '' },
  initialStatus: { type: String, default: 'all' },
  responding: { type: Boolean, default: false },
})
const emit = defineEmits(['respond', 'cancelled'])
const { t, locale } = useI18n()
const { isCampaignBookmarked, loadCampaignBookmarks, toggleCampaignBookmark } = useCampaignBookmarks()
const dialog = ref(null)
const page = ref(1)
const heading = ref(null)
watch(page, async () => {
  await nextTick()
  heading.value?.focus({ preventScroll: true })
  heading.value?.scrollIntoView({ block: 'start' })
})
const isActivity = computed(() => props.kind === 'activity')
const cancelOfferId = ref(null)
const reviewApplicationId = ref(null)
const pendingResponse = ref(null)
const isOffers = computed(() => props.kind === 'offers')
const isInquiries = computed(() => props.kind === 'inquiries')
const isInvitations = computed(() => props.kind === 'invitations')
const titleKey = computed(() => isInvitations.value ? 'account.campaignInvitations' : isInquiries.value ? 'account.directRequests' : isOffers.value ? 'account.myOffers' : 'account.myApplications')
const introKey = computed(() => isActivity.value ? 'hiring.activityIntro' : isInvitations.value ? 'account.invitationsIntro' : isInquiries.value ? 'account.inquiriesIntro' : isOffers.value ? 'account.offersIntro' : 'account.applicationsIntro')
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
  if (isActivity.value && item.offer) return campaignCompensationLabel(item.campaign, t, item.offer.amount)
  if (!isInquiries.value) return campaignCompensationLabel(item.campaign, t)
  const amount = item.proposedAmount ?? item.listedPrice
  return amount === null || amount === undefined ? t('creatorProfile.priceByAgreement') : formatMoney(amount, item.proposedAmount !== null && item.proposedAmount !== undefined ? item.currency : item.listedPriceCurrency)
}
function hasChat(item) {
  return isInquiries.value ? item.canChat : Boolean(item.conversationId)
}
function packageLabel(selection) {
  return selection.type === 'service' ? t('creatorProfile.servicePackage') : selection.type === 'other' ? t('creatorProfile.somethingElse') : selection.title
}
const query = ref(props.initialQuery || '')
watch(() => props.initialQuery, value => { query.value = value || '' })
const statusFilter = ref('all')
watch(() => props.initialStatus, value => { statusFilter.value = ['all', 'pending', 'offered', 'hired', 'finished', 'cancelled', 'rejected', 'closed'].includes(value) ? value : 'all' }, { immediate: true })
function statusGroup(item) {
  if (isActivity.value) {
    const stage = campaignActivityStage(item)
    return ['applied', 'invited', 'discussing'].includes(stage) ? 'pending' : stage
  }
  if (!isInquiries.value && (item.status === 'cancelled' || item.campaign?.status === 'cancelled')) return 'cancelled'
  if (isInvitations.value) {
    if (item.status === 'accepted') return 'accepted'
    if (item.status === 'declined') return 'rejected'
    return invitationExpired(item) ? 'expired' : 'pending'
  }
  if (item.campaign?.status === 'finished' && (item.status === 'accepted' || item.offer?.status === 'accepted')) return 'finished'
  if (item.campaign?.status === 'closed') return 'closed'
  if (['rejected', 'offer_declined'].includes(item.status)) return 'rejected'
  if (item.status === 'accepted') return 'accepted'
  return 'pending'
}
const statusFilters = computed(() => {
  const keys = isActivity.value
    ? [['all', 'account.activityAll'], ['pending', 'account.pending'], ['offered', 'hiring.offerWaiting'], ['hired', 'hiring.hired'], ['finished', 'account.finished'], ['cancelled', 'account.cancelled'], ['rejected', 'account.rejected'], ['closed', 'campaignChat.closed']]
    : [['all', 'account.activityAll'], ['pending', isOffers.value ? 'account.offerAwaitingResponse' : 'account.pending'], ['accepted', 'account.accepted'], ['finished', 'account.finished'], ['rejected', 'account.rejected'], [isInvitations.value ? 'expired' : 'closed', isInvitations.value ? 'account.invitationExpired' : 'campaignChat.closed'], ['cancelled', 'account.cancelled']]
  return keys.map(([value, label]) => ({ value, label: t(label), count: value === 'all' ? props.items.length : props.items.filter(item => statusGroup(item) === value).length }))
    .filter(filter => (!isInquiries.value || !['closed', 'cancelled', 'finished'].includes(filter.value)) && (!isActivity.value || filter.value === 'all' || filter.count > 0))
})
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
  if (isActivity.value) {
    const stage = campaignActivityStage(offer)
    return t(['finished', 'cancelled', 'rejected'].includes(stage) ? `account.${stage}` : stage === 'closed' ? 'campaignChat.closed' : `hiring.${stage === 'offered' ? 'offerWaiting' : stage}`)
  }
  if (statusGroup(offer) === 'finished') return t('account.finished')
  if (!isInquiries.value && (offer.status === 'accepted' || offer.offer?.status === 'accepted') && !isInvitations.value) return t('hiring.hired')
  if (statusGroup(offer) === 'cancelled') return t('account.cancelled')
  if (isInvitations.value) return t(statusGroup(offer) === 'expired' ? 'account.invitationExpired' : `account.${statusGroup(offer)}`)
  if (offer.campaign?.status === 'closed') return t('campaignChat.closed')
  const key = offer.status === 'offer_declined' ? 'offerDeclined' : offer.status
  return t(isOffers.value && key === 'pending' ? 'account.offerAwaitingResponse' : `account.${key}`)
}

function responseSummary(item) {
  const offer = activeOffer(item)
  const amount = t('hiring.reviewAmount', { campaign: item.campaign.title, amount: campaignCompensationLabel(item.campaign, t, offer.amount) })
  return offer.dueDate ? `${amount} ${t('hiring.deliveryDue', { date: formatDate(offer.dueDate) })}` : amount
}

function activeOffer(item) { return isOffers.value ? item : item?.offer }
function canAnswer(item) {
  if (!item || item.readOnly || item.campaign?.status !== 'open') return false
  return activeOffer(item)?.status === 'pending' || (item.source === 'invitation' && item.status === 'pending' && !invitationExpired(item))
}
function requestResponse(item, decision) {
  if (activeOffer(item)?.status === 'pending') { closeCampaign(); pendingResponse.value = { item, decision } }
  else emit('respond', item, decision)
}
function confirmResponse() {
  const response = pendingResponse.value
  pendingResponse.value = null
  if (response) emit('respond', response.item, response.decision)
}
function canCancel(item) { return activeOffer(item)?.status === 'accepted' && item.campaign?.status !== 'finished' && !activeOffer(item)?.workCompletedAt && !item.readOnly }
function canReview(item) { return (item.campaign?.status === 'finished' || activeOffer(item)?.workCompletedAt) && activeOffer(item)?.status === 'accepted' && !item.readOnly }
function applicationId(item) { return isOffers.value ? item.applicationId : item.id }

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
  if (campaign.value && props.canRespond) {
    try { await loadCampaignBookmarks() }
    catch (cause) { if (!disposed && selectedItemId.value === offer.id) bookmarkError.value = cause.message }
  }
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
watch(() => currentUser.value?.id, () => {
  cancelOfferId.value = null
  reviewApplicationId.value = null
  pendingResponse.value = null
  closeCampaign()
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
            <th scope="col">{{ t(isActivity ? 'hiring.compensation' : 'campaignChat.budget') }}</th>
            <th v-if="!isInquiries" scope="col">{{ t(isInvitations ? 'account.closesAt' : 'account.offerDeadline') }}</th>
            <th scope="col">{{ t(isActivity ? 'hiring.activityDate' : isInvitations ? 'account.hiredCreators' : isOffers || isInquiries ? 'account.activityReceived' : 'account.activitySubmitted') }}</th>
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
              <td class="creator-offers__budget"><span class="creator-offers__mobile-label">{{ t(isActivity ? 'hiring.compensation' : 'campaignDetail.budget') }}</span><strong>{{ itemBudget(offer) }}</strong></td>
              <td v-if="!isInquiries"><span class="creator-offers__mobile-label">{{ t(isInvitations ? 'account.closesAt' : 'account.offerDeadline') }}</span><span v-if="offer.campaign.closesAt" class="creator-offers__deadline"><CalendarDays :size="17" aria-hidden="true" /><time :datetime="offer.campaign.closesAt">{{ formatDate(offer.campaign.closesAt) }}</time></span><small v-if="isInvitations" class="campaign-invitations__deadline-hint">{{ deadlineLabel(offer.campaign) }}</small></td>
              <td v-if="isInvitations"><span class="creator-offers__mobile-label">{{ t('account.hiredCreators') }}</span><CampaignHiringProgress :campaign="offer.campaign" /></td>
              <td v-else><span class="creator-offers__mobile-label">{{ t(isActivity ? 'hiring.activityDate' : isOffers || isInquiries ? 'account.activityReceived' : 'account.activitySubmitted') }}</span><time v-if="offer.createdAt" :datetime="offer.createdAt">{{ formatDate(offer.createdAt) }}</time></td>
              <td><span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(offer)}`"><i :class="`activity-dot activity-dot--${statusGroup(offer)}`" aria-hidden="true"></i>{{ statusLabel(offer) }}</span></td>
              <td v-if="isInvitations" class="creator-offers__action-cell"><div class="creator-offers__actions campaign-invitations__actions">
                <div class="campaign-invitations__primary-actions">
                  <template v-if="statusGroup(offer) === 'pending' && !offer.readOnly">
                    <button class="button button--dark" type="button" :disabled="!canRespond || responding" @click="emit('respond', offer, 'accept')">{{ t('account.acceptInvitation') }}</button>
                    <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', offer, 'decline')">{{ t('account.declineInvitation') }}</button>
                  </template>
                  <LocalizedLink v-else-if="offer.status === 'accepted' && hasChat(offer)" class="button button--dark" :to="chatRoute(offer)">{{ t('account.openChat') }}</LocalizedLink>
                  <button v-else class="button button--outline" type="button" @click="openCampaign(offer)">{{ t('account.invitationViewDetails') }}</button>
                </div>
                <AdminRowActions :label="`${t('account.activityActions')}: ${offer.campaign.title}`">
                  <button class="admin-row-actions__item" role="menuitem" type="button" @click="openCampaign(offer)"><Eye :size="16" aria-hidden="true" />{{ t('account.invitationViewDetails') }}</button>
                  <LocalizedLink v-if="offer.campaign.slug" class="admin-row-actions__item" role="menuitem" :to="{ name: 'campaign-detail', params: { slug: offer.campaign.slug } }"><ExternalLink :size="16" aria-hidden="true" />{{ t('campaignCard.viewBrief') }}</LocalizedLink>
                </AdminRowActions>
              </div></td>
              <td v-else class="creator-offers__action-cell"><div class="creator-offers__actions">
                <template v-if="isActivity && canAnswer(offer)">
                  <button class="creator-offers__response creator-offers__response--accept" type="button" :disabled="!canRespond || responding" @click="requestResponse(offer, 'accept')"><Check :size="14" aria-hidden="true" />{{ t('account.accept') }}</button>
                  <button class="creator-offers__response" type="button" :disabled="!canRespond || responding" @click="requestResponse(offer, activeOffer(offer) ? 'reject' : 'decline')"><X :size="14" aria-hidden="true" />{{ t('account.reject') }}</button>
                </template>
                <AdminRowActions v-if="isActivity" :label="`${t('account.activityActions')}: ${itemTitle(offer)}`">
                  <button class="admin-row-actions__item" role="menuitem" type="button" @click="openCampaign(offer)"><Eye :size="16" aria-hidden="true" />{{ t('account.activityView') }}</button>
                  <LocalizedLink v-if="hasChat(offer)" class="admin-row-actions__item" role="menuitem" :to="chatRoute(offer)"><MessageCircle :size="16" aria-hidden="true" />{{ t('account.activityChat') }}</LocalizedLink>
                  <button v-if="canReview(offer)" class="admin-row-actions__item" role="menuitem" type="button" @click="reviewApplicationId = applicationId(offer)"><Star :size="16" aria-hidden="true" />{{ t('reviews.action') }}</button>
                  <button v-if="canCancel(offer)" class="admin-row-actions__item" role="menuitem" type="button" @click="cancelOfferId = activeOffer(offer).id"><Ban :size="16" aria-hidden="true" />{{ t('hiring.cancelCollaboration') }}</button>
                </AdminRowActions>
                <ReviewAction v-if="!isActivity && !isInquiries && offer.campaign?.status === 'finished' && (isOffers ? offer.status === 'accepted' : offer.offer?.status === 'accepted') && !offer.readOnly" :application-id="isOffers ? offer.applicationId : offer.id" />
                <LocalizedLink v-if="!isActivity && hasChat(offer)" class="button button--outline" :to="chatRoute(offer)"><MessageCircle :size="16" aria-hidden="true" />{{ t('account.activityChat') }}</LocalizedLink>
                <button v-if="!isActivity" class="button button--dark" type="button" :aria-label="`${t(isInquiries ? 'account.activityView' : 'campaignCard.viewBrief')}: ${itemTitle(offer)}`" @click="openCampaign(offer)">{{ t('account.activityView') }}<ArrowRight :size="16" aria-hidden="true" /></button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <DirectoryPagination v-model:page="page" :page-size="10" :show-page-size="false" :show-single-page="true" :total="filteredItems.length" :status-label="t(isInvitations ? 'account.invitationsShown' : 'account.activityShown', { shown: pageItems.length, total: filteredItems.length })" />
    </template>
    <dialog ref="dialog" class="offer-campaign-modal wave-modal wave-modal--wide wave-modal--scroll-body" aria-modal="true" :aria-labelledby="`${id}-title`" @cancel.prevent="closeCampaign" @close="closeCampaign" @click.self="closeCampaign">
      <div v-if="selectedItem" class="offer-campaign-modal__layout">
        <header v-if="campaign" class="offer-campaign-modal__header">
          <div class="offer-campaign-modal__cover"><CardImage :image="campaign.coverImage" :src="campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER" alt="" sizes="620px" /></div>
          <div class="offer-campaign-modal__branding">
            <span class="offer-campaign-modal__logo"><CardImage v-if="campaign.company.logoImage || campaign.company.logoUrl" :image="campaign.company.logoImage" :src="campaign.company.logoUrl" :alt="campaign.company.name" sizes="64px" /><span v-else>{{ campaign.company.name.slice(0, 1) }}</span></span>
            <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`"><i :class="`activity-dot activity-dot--${statusGroup(selectedItem)}`" aria-hidden="true"></i>{{ statusLabel(selectedItem) }}</span>
          </div>
          <p class="creator-offer__company">{{ campaign.company.name }}</p>
          <h2 :id="`${id}-title`" class="wave-modal__title">{{ campaign.title }}</h2>
          <p class="creator-offer__summary">{{ campaign.summary }}</p>
          <div class="offer-campaign-meta">
            <span v-for="channel in campaign.channels" :key="channel"><component :is="platformIcon(channel)" :size="16" aria-hidden="true" />{{ channel }}</span>
            <span v-if="campaignPlace(campaign, locale)"><MapPin :size="16" aria-hidden="true" />{{ campaignPlace(campaign, locale) }}</span>
          </div>
          <button class="offer-campaign-modal__close wave-modal__close wave-modal__close--floating" type="button" :aria-label="t('account.closeCampaignModal')" @click="closeCampaign"><X :size="22" aria-hidden="true" /></button>
        </header>
        <header v-else class="offer-campaign-modal__header offer-campaign-modal__header--inquiry">
          <p class="creator-offer__company">{{ counterpart(selectedItem).name }}</p>
          <h2 :id="`${id}-title`" class="wave-modal__title">{{ itemTitle(selectedItem) }}</h2>
          <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`">{{ statusLabel(selectedItem) }}</span>
          <button class="offer-campaign-modal__close wave-modal__close wave-modal__close--floating" type="button" :aria-label="t('creatorProfile.closeRequest')" @click="closeCampaign"><X :size="22" aria-hidden="true" /></button>
        </header>
        <div v-if="campaign" class="offer-campaign-modal__content">
          <StatusMessage v-if="selectedItem.readOnly" variant="info">{{ t(selectedItem.blocked ? 'safety.blockedHistory' : statusGroup(selectedItem) === 'cancelled' ? 'hiring.cancelledNotice' : 'campaignChat.deletedAccountNotice') }}</StatusMessage>
          <StatusMessage v-else-if="campaign.status === 'finished'" variant="info">{{ t('account.campaignFinishedNotice') }}</StatusMessage>
          <CampaignHiringProgress :campaign="campaign" />
          <section>
            <h3>{{ t('account.offerCampaignAbout') }}</h3>
            <RichTextContent :html="campaign.description" />
          </section>
          <dl class="offer-campaign-modal__facts">
            <div><Wallet :size="23" aria-hidden="true" /><dt>{{ t('campaignDetail.budget') }}</dt><dd><CampaignCompensation :campaign="campaign" /></dd></div>
            <div v-if="campaign.closesAt"><CalendarDays :size="23" aria-hidden="true" /><dt>{{ t('account.offerDeadline') }}</dt><dd>{{ formatDate(campaign.closesAt) }}</dd></div>
            <div v-if="campaignPlace(campaign, locale)"><MapPin :size="23" aria-hidden="true" /><dt>{{ t('campaignDetail.location') }}</dt><dd>{{ campaignPlace(campaign, locale) }}</dd></div>
            <div v-if="campaign.deliverables?.length"><Camera :size="23" aria-hidden="true" /><dt>{{ t('account.deliverables') }}</dt><dd><ul><li v-for="(deliverable, index) in campaign.deliverables" :key="index">{{ deliverable }}</li></ul></dd></div>
          </dl>
          <section v-if="isOffers || activeOffer(selectedItem)" class="offer-campaign-modal__offer">
            <h3>{{ t('account.offerDetails') }}</h3>
            <strong><CampaignCompensation :campaign="campaign" :amount="activeOffer(selectedItem).amount" /></strong>
            <p v-if="activeOffer(selectedItem).dueDate">{{ t('hiring.deliveryDue', { date: formatDate(activeOffer(selectedItem).dueDate) }) }}</p>
            <p v-if="activeOffer(selectedItem).message">{{ activeOffer(selectedItem).message }}</p>
            <span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(selectedItem)}`">{{ statusLabel(selectedItem) }}</span>
            <div v-if="activeOffer(selectedItem)?.status === 'pending' && campaign.status === 'open' && !selectedItem.readOnly" class="button-row">
              <button class="creator-offers__response creator-offers__response--accept" type="button" :disabled="!canRespond || responding" @click="requestResponse(selectedItem, 'accept')">{{ t('account.accept') }}</button>
              <button class="creator-offers__response" type="button" :disabled="!canRespond || responding" @click="requestResponse(selectedItem, 'reject')">{{ t('account.reject') }}</button>
            </div>
          </section>
          <section v-else class="offer-campaign-modal__offer">
            <h3>{{ t(isInvitations || selectedItem.source === 'invitation' ? 'campaignChat.invitationMessage' : 'account.applicationMessage') }}</h3>
            <p>{{ selectedItem.message }}</p>
            <span class="creator-offer__status">{{ statusLabel(selectedItem) }}</span>
            <div v-if="(isInvitations || selectedItem.source === 'invitation') && canAnswer(selectedItem) && !selectedItem.readOnly" class="button-row">
              <button class="creator-offers__response creator-offers__response--accept" type="button" :disabled="!canRespond || responding" @click="requestResponse(selectedItem, 'accept')">{{ t('account.acceptInvitation') }}</button>
              <button class="button button--outline" type="button" :disabled="!canRespond || responding" @click="emit('respond', selectedItem, 'decline')">{{ t('account.declineInvitation') }}</button>
            </div>
          </section>
          <StatusMessage v-if="bookmarkError" variant="error">{{ bookmarkError }}</StatusMessage>
        </div>
        <div v-else class="offer-campaign-modal__content">
          <h3>{{ t('account.directRequests') }}</h3>
          <StatusMessage v-if="selectedItem.readOnly" variant="info">{{ t(selectedItem.blocked ? 'safety.blockedHistory' : statusGroup(selectedItem) === 'cancelled' ? 'hiring.cancelledNotice' : 'campaignChat.deletedAccountNotice') }}</StatusMessage>
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
          <div v-if="selectedItem.role === 'creator' && selectedItem.status === 'pending' && !selectedItem.readOnly" class="button-row">
            <button class="creator-offers__response creator-offers__response--accept" type="button" :disabled="!canRespond || responding" @click="requestResponse(selectedItem, 'accept')">{{ t('account.accept') }}</button>
            <button class="creator-offers__response" type="button" :disabled="!canRespond || responding" @click="requestResponse(selectedItem, 'reject')">{{ t('account.reject') }}</button>
          </div>
        </div>
        <footer v-if="campaign || hasChat(selectedItem)" class="offer-campaign-modal__footer">
          <button v-if="campaign?.slug && !selectedItem.readOnly" class="button button--outline" type="button" :disabled="bookmarkBusy || !canRespond" :aria-pressed="isCampaignBookmarked(campaign.slug)" @click="toggleBookmark"><Bookmark :size="18" aria-hidden="true" />{{ t(isCampaignBookmarked(campaign.slug) ? 'campaignCard.removeBookmark' : 'campaignCard.addBookmark') }}</button>
          <LocalizedLink v-if="hasChat(selectedItem)" class="button button--dark" :to="chatRoute(selectedItem)" @click="closeCampaign"><MessageCircle :size="18" aria-hidden="true" />{{ t('account.openChat') }}</LocalizedLink>
        </footer>
      </div>
    </dialog>
    <CampaignCancellationAction v-if="cancelOfferId" :offer-id="cancelOfferId" auto-open hide-trigger @closed="cancelOfferId = null" @cancelled="emit('cancelled')" />
    <ReviewAction v-if="reviewApplicationId" :application-id="reviewApplicationId" auto-open hide-trigger @closed="reviewApplicationId = null" />
    <ConfirmationModal :open="Boolean(pendingResponse)" :title="t('hiring.confirmTitle')" :message="pendingResponse ? `${responseSummary(pendingResponse.item)}${pendingResponse.decision === 'reject' ? ` ${t('hiring.declineHint')}` : ''}` : ''" :confirm-label="t(pendingResponse?.decision === 'accept' ? 'hiring.acceptOffer' : 'hiring.declineOffer')" :cancel-label="t('safety.cancel')" @update:open="!$event && (pendingResponse = null)" @confirm="confirmResponse" />
  </section>
</template>

<style lang="scss" src="../../scss/components/account/CreatorCampaignActivity.scss"></style>
