<script setup>
import CampaignCompensation from '../components/campaigns/CampaignCompensation.vue'
import PublicShareButton from '../components/shared/PublicShareButton.vue'
import { rememberRegistrationIntent } from '../lib/registrationIntent'
import PresentationDemoBadge from '../components/shared/PresentationDemoBadge.vue'
import MarketplaceSafetyActions from '../components/shared/MarketplaceSafetyActions.vue'
import CreditActionNotice from '../components/account/CreditActionNotice.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import { proposalFromOffering } from '../lib/agencyServices'
import { refreshCredits } from '../composables/useCredits'
import CampaignHiringProgress from '../components/campaigns/CampaignHiringProgress.vue'
import CampaignApplicationRange from '../components/campaigns/CampaignApplicationRange.vue'
import { campaignPlace } from '../lib/marketplace'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, ArrowRight, BadgeCheck, CalendarDays, ChevronRight, Info, MapPin, Tag, UsersRound } from '@lucide/vue'
import ProfileStats from '../components/shared/ProfileStats.vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import RichTextContent from '../components/shared/RichTextContent.vue'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'

const route = useRoute()
const campaign = ref(null)
const error = ref('')
const applyError = ref('')
const applying = ref(false)
const canApply = computed(() => user.value?.accountType === 'creator' || user.value?.canOfferServices)
const ownCampaign = computed(() => Boolean(user.value?.id) && campaign.value?.company?.ownerId === user.value.id)
const applicationMessage = ref('')
const agencyProposal = ref({ amount: null, scope: '', timeline: '', team: '' })
const selectedOffering = ref('')
const offerings = computed(() => [
  ...(user.value?.profile?.services || []).map(item => ({ key: `service:${item.id}`, label: `${t('agency.services')} · ${item.title}`, item })),
  ...(user.value?.profile?.packages || []).map(item => ({ key: `package:${item.id}`, label: `${t('studio.tabPackages')} · ${item.title}`, item })),
])
const offeringOptions = computed(() => [{ value: '', label: t('agency.customProposal') }, ...offerings.value.map(row => ({ value: row.key, label: row.label }))])
watch(selectedOffering, value => {
  const offering = offerings.value.find(row => row.key === value)
  if (offering && campaign.value) agencyProposal.value = { ...agencyProposal.value, ...proposalFromOffering(offering.item, campaign.value.currency, t) }
})
const applicationSent = ref(false)
const applicationFormOpen = ref(false)
const alreadyApplied = ref(false)
const invitation = ref(null)
const engaged = ref(false)
const collaborationCancelled = ref(false)
const user = ref(null)
const { t, locale } = useI18n()
let campaignGeneration = 0

async function loadCampaign() {
  const generation = ++campaignGeneration
  const slug = route.params.slug
  campaign.value = null
  error.value = ''
  applyError.value = ''
  applicationSent.value = false
  applicationFormOpen.value = false
  alreadyApplied.value = false
  invitation.value = null
  engaged.value = false
  collaborationCancelled.value = false
  selectedOffering.value = ''
  agencyProposal.value = { amount: null, scope: '', timeline: '', team: '' }
  try {
    const [response, session] = await Promise.all([
      apiGet(`/campaigns/${encodeURIComponent(slug)}`),
      apiGet('/auth/session').catch(() => ({ data: null })),
    ])
    if (generation !== campaignGeneration) return
    user.value = session.data
    campaign.value = response.data

    if (canApply.value) {
      try {
        const [applicationsResponse, invitationsResponse] = await Promise.all([apiGet('/me/applications'), apiGet('/me/invitations')])
        if (generation !== campaignGeneration) return
        const application = applicationsResponse.data.find(item => item.campaign.slug === slug)
        alreadyApplied.value = Boolean(application)
        engaged.value = application?.offer?.status === 'accepted'
        collaborationCancelled.value = application?.status === 'cancelled' || application?.offer?.status === 'cancelled'
        invitation.value = invitationsResponse.data.find(item => item.campaign.slug === slug && item.status !== 'declined') || null
      } catch (cause) {
        if (generation !== campaignGeneration) return
        applyError.value = cause.message
      }
    }
  } catch (cause) {
    if (generation !== campaignGeneration) return
    error.value = cause.message
    if (cause.status === 404 && !campaign.value) {
      updateSeo({
        route: { ...route, meta: { ...route.meta, routeName: 'not-found' } },
        locale: locale.value,
        title: t('seo.notFoundTitle'),
        description: t('seo.notFoundDescription'),
        noindex: true,
      })
    }
  }
}

const coverUrl = computed(() => campaign.value?.coverImageUrl || campaign.value?.company?.coverUrl || '')
const categories = computed(() => (campaign.value?.categoryLabels || [campaign.value?.categoryLabel || campaign.value?.category]).filter(Boolean).join(', '))
const stats = computed(() => campaign.value ? [
  { key: 'budget', label: t('campaignDetail.budget') },
  { key: 'spots', label: t('campaignDetail.spots') },
  { key: 'closes', label: t('campaignDetail.closes'), value: formatDate(campaign.value.closesAt) },
  { key: 'location', label: t('campaignDetail.location'), value: campaignPlace(campaign.value, locale.value) },
] : [])
// Phones: a short bar above the tab bar that jumps to the application card.
const showApplyBar = computed(() => Boolean(campaign.value) && campaign.value.status === 'open' && !campaign.value.readOnly
  && !applicationSent.value && !alreadyApplied.value && !engaged.value && !ownCampaign.value && (!user.value || canApply.value))
function openApplication() {
  if (canApply.value && !ownCampaign.value && !invitation.value && user.value?.emailVerified !== false && user.value?.approved) applicationFormOpen.value = true
  const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
  document.getElementById('campaign-apply')?.scrollIntoView({ behavior, block: 'start' })
}

async function applyToCampaign() {
  if (!canApply.value || ownCampaign.value || applying.value || invitation.value || alreadyApplied.value || engaged.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await apiRequest(`/campaigns/${encodeURIComponent(route.params.slug)}/applications`, {
      method: 'POST',
      body: { message: applicationMessage.value, ...(user.value?.profile?.companyType === 'agency' ? { proposal: { ...agencyProposal.value, amount: campaign.value.compensationType === 'voluntary' || agencyProposal.value.amount === '' ? null : agencyProposal.value.amount } } : {}) },
    })
    const updatedCampaign = response?.data?.campaign
    if (updatedCampaign?.applicationRange && updatedCampaign.slug === campaign.value?.slug) {
      campaign.value.applicationRange = updatedCampaign.applicationRange
    }
    refreshCredits().catch(() => {})
    applicationSent.value = true
    applicationMessage.value = ''
    agencyProposal.value = { amount: null, scope: '', timeline: '', team: '' }
    selectedOffering.value = ''
  } catch (cause) {
    applyError.value = cause.message
  } finally {
    applying.value = false
  }
}

watch([() => route.params.slug, locale], loadCampaign)
onBeforeUnmount(() => { ++campaignGeneration })
watch(
  [campaign, locale],
  ([value]) => {
    if (!value) return

    updateSeo({
      route,
      locale: locale.value,
      title: t('seo.campaignProfileTitle', { name: value.title }),
      noindex: value.listed === false || value.readOnly || value.status !== 'open' || new Date(value.closesAt).getTime() < new Date().setHours(0, 0, 0, 0),
      description: value.summary,
      image: value.coverImageUrl,
      mainEntity: {
        '@type': 'CreativeWork',
        name: value.title,
        description: value.summary,
        datePublished: value.publishedAt,
        expires: value.closesAt,
        provider: {
          '@type': 'Organization',
          name: value.company.name,
          url: new URL(
            localizedPath('company-profile', locale.value, { slug: value.company.slug }),
            getSeoOrigin(),
          ).href,
        },
      },
    })
  },
)
onMounted(loadCampaign)
</script>

<template>
  <section v-if="error" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/campaigns">
      <ArrowLeft class="link-arrow" :size="16" aria-hidden="true" /> {{ t('campaignDetail.back') }}
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!campaign" variant="campaign" :label="t('campaignDetail.loading')" />
  <template v-else>
    <main class="campaign-page page-width">
      <nav class="campaign-page__breadcrumb" :aria-label="t('creatorProfile.breadcrumbs')">
        <RouterLink to="/campaigns">{{ t('app.campaigns') }}</RouterLink>
        <ChevronRight :size="14" aria-hidden="true" />
        <span aria-current="page">{{ campaign.title }}</span>
        <PresentationDemoBadge v-if="campaign.isPresentationDemo" pill />
        <PublicShareButton route-name="campaign-detail" :slug="campaign.slug" :title="campaign.title" />
      </nav>

      <section class="campaign-hero" :class="{ 'campaign-hero--no-cover': !coverUrl }" aria-labelledby="campaign-title">
        <div class="campaign-hero__copy">
          <p class="eyebrow">
            {{ categories }}
            <span aria-hidden="true">·</span>
            {{ campaign.status === 'open' ? t('campaignDetail.openCampaign') : t(`account.${campaign.status}`) }}
          </p>
          <h1 id="campaign-title">{{ campaign.title }}</h1>
          <p class="campaign-hero__summary">{{ campaign.summary }}</p>
          <StatusMessage v-if="campaign.compensationType === 'voluntary'">{{ t('compensation.voluntaryHint') }}</StatusMessage>
          <RouterLink
            v-if="!campaign.company.deleted"
            class="campaign-brand"
            :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }"
          >
            <span class="campaign-brand__logo">
              <img v-if="campaign.company.logoUrl" :src="campaign.company.logoUrl" :alt="campaign.company.name">
              <WaveLogo v-else mark />
            </span>
            <span class="campaign-brand__name">
              <strong>{{ campaign.company.name }}<BadgeCheck v-if="campaign.company.verified" :size="16" :aria-label="t('campaignCard.verified')" /></strong>
              <small>{{ campaign.company.industry }}</small>
            </span>
            <ChevronRight :size="16" aria-hidden="true" />
          </RouterLink>
          <div v-if="campaign.channels?.length" class="campaign-hero__channels" :aria-label="t('campaignDetail.channels')">
            <span v-for="channel in campaign.channels" :key="channel">{{ channel }}</span>
          </div>
        </div>
        <figure v-if="coverUrl" class="campaign-hero__cover">
          <!-- The whole uploaded cover, at its own proportions: never cropped. -->
          <img :src="coverUrl" :width="campaign.coverImage?.width || undefined" :height="campaign.coverImage?.height || undefined" alt="" fetchpriority="high" decoding="async">
          <figcaption v-if="campaign.applicationRange" class="campaign-hero__range"><CampaignApplicationRange :range="campaign.applicationRange" /></figcaption>
        </figure>
      </section>

      <ProfileStats :items="stats" :label="t('campaignDetail.details')">
        <template #value-budget><CampaignCompensation :campaign="campaign" /></template>
        <template #value-spots><CampaignHiringProgress :campaign="campaign" /></template>
      </ProfileStats>

      <div class="campaign-layout">
        <div class="campaign-layout__main">
          <section class="campaign-section" aria-labelledby="campaign-idea-title">
            <header class="campaign-section__head"><h2 id="campaign-idea-title">{{ t('campaignDetail.idea') }}</h2><p>{{ t('campaignDetail.ideaHeadline') }}</p></header>
            <div class="campaign-detail-card"><RichTextContent :html="campaign.description" /></div>
          </section>
          <section v-if="campaign.deliverables?.length" class="campaign-section" aria-labelledby="campaign-deliverables-title">
            <header class="campaign-section__head"><h2 id="campaign-deliverables-title">{{ t('campaignDetail.deliverables') }}</h2><p>{{ t('campaignDetail.deliverablesHint') }}</p></header>
            <ul class="campaign-deliverables">
              <li v-for="deliverable in campaign.deliverables" :key="deliverable">
                <span class="campaign-deliverables__icon"><Tag :size="18" aria-hidden="true" /></span>
                <span>{{ deliverable }}</span>
              </li>
            </ul>
          </section>
          <section class="campaign-section" aria-labelledby="campaign-brand-title">
            <header class="campaign-section__head">
              <h2 id="campaign-brand-title">{{ t('companyProfile.aboutEyebrow') }}</h2>
              <RouterLink v-if="!campaign.company.deleted" class="campaign-section__link" :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }">{{ t('companyDirectory.viewProfile') }} <ArrowRight :size="15" aria-hidden="true" /></RouterLink>
            </header>
            <div class="campaign-detail-card campaign-company">
              <component
                :is="campaign.company.deleted ? 'div' : RouterLink"
                class="campaign-company__heading"
                :class="{ 'campaign-company__heading--link': !campaign.company.deleted }"
                :to="campaign.company.deleted ? undefined : { name: 'company-profile', params: { slug: campaign.company.slug } }"
              >
                <span class="campaign-brand__logo campaign-brand__logo--large">
                  <img v-if="campaign.company.logoUrl" :src="campaign.company.logoUrl" alt="">
                  <WaveLogo v-else mark />
                </span>
                <div>
                  <strong>{{ campaign.company.name }}<BadgeCheck v-if="campaign.company.verified" :size="15" :aria-label="t('campaignCard.verified')" /></strong>
                  <small>{{ campaign.company.industry }}</small>
                </div>
              </component>
              <RichTextContent v-if="campaign.company.about" :html="campaign.company.about" />
              <p v-else class="campaign-company__empty">{{ t('companyProfile.profileLabel') }}</p>
            </div>
          </section>
          <MarketplaceSafetyActions kind="campaign" :slug="campaign.slug" :owner-slug="campaign.company.slug" />
        </div>

        <aside class="campaign-layout__aside">
          <section id="campaign-apply" class="campaign-apply" aria-labelledby="campaign-application-title">
            <div class="campaign-apply__top">
              <div class="campaign-apply__budget"><small id="campaign-application-title">{{ t('campaignDetail.budget') }}</small><strong><CampaignCompensation :campaign="campaign" /></strong></div>
              <span class="campaign-apply__status" :class="`is-${campaign.status}`"><i aria-hidden="true"></i>{{ t(`account.${campaign.status}`) }}</span>
            </div>
            <dl class="campaign-apply__facts">
              <div><dt><UsersRound :size="16" aria-hidden="true" />{{ t('campaignDetail.spots') }}</dt><dd><CampaignHiringProgress :campaign="campaign" /></dd></div>
              <div><dt><CalendarDays :size="16" aria-hidden="true" />{{ t('campaignDetail.closes') }}</dt><dd>{{ formatDate(campaign.closesAt) }}</dd></div>
              <div><dt><MapPin :size="16" aria-hidden="true" />{{ t('campaignDetail.location') }}</dt><dd>{{ campaignPlace(campaign, locale) }}</dd></div>
            </dl>
            <p v-if="campaign.applicationRange" class="campaign-apply__range"><CampaignApplicationRange :range="campaign.applicationRange" /></p>
              <div v-if="campaign.status === 'cancelled'" class="brief-notice" role="status">{{ t('account.cancelled') }}</div>
              <div v-else-if="campaign.readOnly" class="brief-notice" role="status">{{ t('campaignChat.deletedAccountNotice') }}</div>
              <StatusMessage v-else-if="ownCampaign">{{ t('campaignDetail.ownCampaignNotice') }}</StatusMessage>
              <div
                v-else-if="!campaign.readOnly && canApply && user.emailVerified === false"
                class="brief-notice"
              >
                <p>{{ t('account.emailUnverified') }}</p>
                <RouterLink class="button button--dark button--full" to="/account">
                  {{ t('campaignDetail.verifyEmailInAccount') }} <ArrowRight class="link-arrow" :size="16" aria-hidden="true" />
                </RouterLink>
              </div>
              <div
                v-else-if="canApply && !user.approved"
                class="brief-notice"
                role="status"
              >
                {{ t('account.approvalPendingNotice') }}
              </div>
              <div v-else-if="campaign.status !== 'open'" class="brief-notice" role="status">{{ t(`account.${campaign.status}`) }}</div>
              <div v-else-if="collaborationCancelled" class="brief-notice" role="status">{{ t('hiring.cancelledNotice') }}</div>
              <div v-else-if="engaged" class="brief-notice" role="status">{{ t('hiring.hired') }}</div>
              <div v-else-if="invitation" class="brief-notice" role="status">
                <p>{{ t('hiring.invitedHint') }}</p>
                <RouterLink class="button button--dark button--full" :to="invitation.conversationId ? { name: 'messages', query: { conversation: invitation.conversationId } } : { name: 'account-applications' }">{{ t(invitation.conversationId ? 'account.activityChat' : 'hiring.viewInvitation') }} <ArrowRight :size="17" aria-hidden="true" /></RouterLink>
              </div>
              <div v-else-if="canApply && alreadyApplied" class="brief-notice" role="status">
                {{ t('campaignDetail.alreadyApplied') }}
              </div>
              <div v-else-if="canApply && !ownCampaign && !applicationSent" class="brief-application">
                <button
                  v-if="!applicationFormOpen"
                  class="button button--dark button--full"
                  type="button"
                  @click="applicationFormOpen = true"
                >
                  {{ t('account.apply') }} <ArrowRight :size="17" aria-hidden="true" />
                </button>
                <form v-else v-form-validation @submit.prevent="applyToCampaign">
                  <CreditActionNotice action="application" />
                  <fieldset v-if="user?.profile?.companyType === 'agency'" class="agency-proposal-fields">
                    <legend>{{ t('agency.proposal') }}</legend>
                    <SingleSelect v-if="offerings.length" v-model="selectedOffering" :options="offeringOptions" :label="t('agency.chooseOffering')" />
                    <p v-if="offerings.length" class="creator-studio__hint">{{ t('agency.chooseOfferingHint') }}</p>
                    <label v-if="campaign.compensationType !== 'voluntary'" class="form-field"><span>{{ t('agency.amount') }} ({{ campaign.currency }})</span><input v-model.number="agencyProposal.amount" type="number" min="1" max="10000000" /></label>
                    <label class="form-field"><span>{{ t('agency.scope') }}</span><textarea v-model.trim="agencyProposal.scope" maxlength="2000"></textarea></label>
                    <label class="form-field"><span>{{ t('agency.timeline') }}</span><input v-model.trim="agencyProposal.timeline" maxlength="240" /></label>
                    <label class="form-field"><span>{{ t('agency.team') }}</span><input v-model.trim="agencyProposal.team" maxlength="500" /></label>
                  </fieldset>
                  <label class="field-label" for="application-message">{{ t('account.applicationMessage') }}</label>
                  <textarea
                    id="application-message"
                    v-model="applicationMessage"
                    required
                    minlength="20"
                    maxlength="1200"
                    :placeholder="t('account.applicationPlaceholder')"
                  />
                  <StatusMessage v-if="applyError" variant="error">{{ applyError }}</StatusMessage>
                  <div class="brief-application__actions">
                    <button class="button button--outline" type="button" @click="applicationFormOpen = false">
                      {{ t('account.richTextCancel') }}
                    </button>
                    <button
                      class="button button--dark"
                      type="submit"
                      :disabled="applying"
                    >
                      {{ t('account.sendApplication') }} <ArrowRight :size="17" aria-hidden="true" />
                    </button>
                  </div>
                </form>
              </div>
              <StatusMessage v-else-if="applicationSent" variant="success" :title="t('campaignDetail.applicationSentTitle')">
                {{ t('campaignDetail.applicationSentText') }}
              </StatusMessage>
              <div v-else-if="!user" class="brief-application-card__guest">
                <RouterLink class="button button--dark button--full" :to="{ name: 'register', query: { accountType: 'creator', returnTo: route.path } }" @click="rememberRegistrationIntent(route.path, 'creator')">
                  {{ t('registrationPrompts.campaignCreator.action') }} <ArrowRight :size="17" aria-hidden="true" />
                </RouterLink>
                <p>{{ t('account.signInToApply') }}</p>
              </div>
              <StatusMessage v-else>{{ t('campaignDetail.applicantsOnlyNotice') }}</StatusMessage>
            <p class="campaign-apply__note"><Info :size="15" aria-hidden="true" />{{ t('publicProfile.paymentNote') }}</p>
          </section>
          <section v-if="campaign.status === 'open' && !campaign.readOnly" class="campaign-steps" aria-labelledby="campaign-steps-title">
            <h2 id="campaign-steps-title">{{ t('campaignDetail.howTitle') }}</h2>
            <ol>
              <li v-for="index in 3" :key="index"><span aria-hidden="true">{{ index }}</span><div><strong>{{ t(`campaignDetail.how${index}Title`) }}</strong>{{ t(`campaignDetail.how${index}Text`) }}</div></li>
            </ol>
          </section>
        </aside>
      </div>
    </main>
    <div v-if="showApplyBar" class="campaign-apply-bar" role="group" :aria-label="t('campaignDetail.budget')">
      <div><small>{{ t('campaignDetail.budget') }}</small><strong><CampaignCompensation :campaign="campaign" /></strong></div>
      <button class="button button--dark" type="button" @click="openApplication">{{ t('campaignDetail.applyShort') }}</button>
    </div>
  </template>
</template>

<style lang="scss" src="../scss/views/CampaignDetailView.scss"></style>
