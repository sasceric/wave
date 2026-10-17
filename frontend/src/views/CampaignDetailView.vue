<script setup>
import RegistrationPrompt from '../components/shared/RegistrationPrompt.vue'
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
import { ArrowRight, BadgeCheck, CalendarDays, DollarSign, MapPin, Tag, UsersRound } from '@lucide/vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import RichTextContent from '../components/shared/RichTextContent.vue'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'

const route = useRoute()
const campaign = ref(null)
const error = ref('')
const applyError = ref('')
const applying = ref(false)
const canApply = computed(() => user.value?.accountType === 'creator' || user.value?.canOfferServices)
const ownCampaign = computed(() => campaign.value?.company?.ownerId === user.value?.id)
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

async function applyToCampaign() {
  if (applying.value || invitation.value || alreadyApplied.value || engaged.value) return
  applying.value = true
  applyError.value = ''
  try {
    const response = await apiRequest(`/campaigns/${encodeURIComponent(route.params.slug)}/applications`, {
      method: 'POST',
      body: { message: applicationMessage.value, ...(user.value?.profile?.companyType === 'agency' ? { proposal: { ...agencyProposal.value, amount: agencyProposal.value.amount === '' ? null : agencyProposal.value.amount } } : {}) },
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
      noindex: value.readOnly || value.status !== 'open' || new Date(value.closesAt).getTime() < new Date().setHours(0, 0, 0, 0),
      description: value.summary,
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
      {{ t('campaignDetail.back') }} ↗
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!campaign" variant="campaign" :label="t('campaignDetail.loading')" />
  <template v-else>
    <section class="brief-hero">
      <div v-if="campaign.coverImageUrl || campaign.company.coverUrl" class="brief-hero__image" aria-hidden="true">
        <img :src="campaign.coverImageUrl || campaign.company.coverUrl" alt="" fetchpriority="high" decoding="async">
      </div>
      <div class="page-width brief-hero__inner">
        <RouterLink class="back-link" to="/campaigns">{{ t('campaignDetail.back') }}</RouterLink>
        <div class="brief-hero__grid">
          <div class="brief-hero__copy">
            <p class="eyebrow">
              {{ (campaign.categoryLabels || [campaign.categoryLabel || campaign.category]).join(', ') }}
              <span aria-hidden="true">·</span>
              {{ campaign.status === 'open' ? t('campaignDetail.openCampaign') : t(`account.${campaign.status}`) }}
            </p>
            <PresentationDemoBadge v-if="campaign.isPresentationDemo" />
            <h1>{{ campaign.title }}</h1>
            <MarketplaceSafetyActions kind="campaign" :slug="campaign.slug" :owner-slug="campaign.company.slug" />
            <p class="brief-hero__summary">{{ campaign.summary }}</p>
            <RouterLink
              class="brief-hero__brand brief-hero__brand-link"
              v-if="!campaign.company.deleted"
              :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }"
            >
              <span class="brand-avatar brand-avatar--large">
                <img v-if="campaign.company.logoUrl" :src="campaign.company.logoUrl" :alt="campaign.company.name">
                <WaveLogo v-else mark />
              </span>
              <span>
                {{ campaign.company.name }}
                <small>{{ campaign.company.industry }}</small>
              </span>
              <BadgeCheck
                v-if="campaign.company.verified"
                class="brief-hero__verified"
                :size="16"
                :aria-label="t('campaignCard.verified')"
              />
            </RouterLink>
          </div>

          <aside class="brief-application-card" aria-labelledby="campaign-application-title">
            <div class="brief-application-card__top">
              <p id="campaign-application-title" class="eyebrow">{{ campaign.status === 'open' ? t('campaignDetail.openCampaign') : t(`account.${campaign.status}`) }}</p>
              <span class="brief-status"><i></i>{{ t(`account.${campaign.status}`) }}</span>
            </div>
            <div class="brief-hero-fact">
              <DollarSign :size="18" aria-hidden="true" />
              <span>{{ t('campaignDetail.budget') }}</span>
              <strong>{{ formatMoney(campaign.budgetMin, campaign.currency) }}–{{ formatMoney(campaign.budgetMax, campaign.currency) }}</strong>
            </div>
            <div class="brief-hero-fact">
              <UsersRound :size="18" aria-hidden="true" />
              <span>{{ t('campaignDetail.spots') }}</span>
              <strong><CampaignHiringProgress :campaign="campaign" /></strong>
            </div>
            <div class="brief-hero-fact">
              <CalendarDays :size="18" aria-hidden="true" />
              <span>{{ t('campaignDetail.closes') }}</span>
              <strong>{{ formatDate(campaign.closesAt) }}</strong>
            </div>
            <p v-if="campaign.applicationRange" class="brief-application-card__proposals">
              <CampaignApplicationRange :range="campaign.applicationRange" />
            </p>

            <div v-if="campaign.status === 'cancelled'" class="brief-notice" role="status">{{ t('account.cancelled') }}</div>
            <div v-else-if="campaign.readOnly" class="brief-notice" role="status">{{ t('campaignChat.deletedAccountNotice') }}</div>
            <div
              v-else-if="!campaign.readOnly && canApply && user.emailVerified === false"
              class="brief-notice"
            >
              <p>{{ t('account.emailUnverified') }}</p>
              <RouterLink class="button button--dark button--full" to="/account">
                {{ t('campaignDetail.verifyEmailInAccount') }} <span aria-hidden="true">↗</span>
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
                  <label class="form-field"><span>{{ t('agency.amount') }} ({{ campaign.currency }})</span><input v-model.number="agencyProposal.amount" type="number" min="1" max="10000000" /></label>
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
            <div v-else-if="applicationSent" class="brief-notice" role="status">
              {{ t('account.applicationSent') }}
            </div>
            <div v-else-if="!user" class="brief-application-card__guest">
              <RouterLink class="button button--dark button--full" :to="{ name: 'account', query: { mode: 'register', accountType: 'creator', returnTo: route.path } }" @click="rememberRegistrationIntent(route.path, 'creator')">
                {{ t('registrationPrompts.campaignCreator.action') }} <ArrowRight :size="17" aria-hidden="true" />
              </RouterLink>
              <p>{{ t('account.signInToApply') }}</p>
            </div>
            <a
              v-else
              class="button button--outline button--full"
              :href="`mailto:hello@wave.example?subject=${encodeURIComponent(t('campaignDetail.emailSubject'))}`"
            >
              {{ t('campaignDetail.askAbout') }} <ArrowRight :size="17" aria-hidden="true" />
            </a>
          </aside>
        </div>
      </div>
    </section>

    <section class="brief-layout page-width">
      <article class="brief-copy">
        <p class="eyebrow">{{ t('campaignDetail.idea') }}</p>
        <h2>{{ t('campaignDetail.ideaHeadline') }}</h2>
        <RichTextContent :html="campaign.description" />
        <div class="brief-copy__section">
          <p class="eyebrow">{{ t('campaignDetail.deliverables') }}</p>
          <ul v-if="campaign.deliverables?.length" class="brief-deliverables">
            <li v-for="deliverable in campaign.deliverables" :key="deliverable">
              <span class="brief-deliverables__icon"><Tag :size="18" aria-hidden="true" /></span>
              <span>{{ deliverable }}</span>
            </li>
          </ul>
        </div>
        <div v-if="campaign.channels?.length" class="brief-copy__section">
          <p class="eyebrow">{{ t('campaignDetail.channels') }}</p>
          <div class="brief-channel-list">
            <span v-for="channel in campaign.channels" :key="channel">{{ channel }}</span>
          </div>
        </div>
      </article>
      <aside class="brief-sidebar">
        <RegistrationPrompt v-if="campaign.status === 'open' && !campaign.readOnly" audience="creator" variant="campaign" :return-to="route.path" />
        <section v-if="!user && campaign.status === 'open' && !campaign.readOnly" class="brief-aside">
          <h2>{{ t('registrationPrompts.collaborationTitle') }}</h2>
          <p>{{ t('registrationPrompts.collaborationDescription') }}</p>
          <p>{{ t('registrationPrompts.noPayments') }}</p>
        </section>
        <section class="brief-aside">
          <div class="brief-aside__top">
            <span class="eyebrow">{{ t('campaignDetail.details') }}</span>
          </div>
          <div class="brief-fact">
            <Tag :size="18" aria-hidden="true" />
            <span>{{ t('campaignDetail.category') }}</span>
            <strong>{{ (campaign.categoryLabels || [campaign.categoryLabel || campaign.category]).join(', ') }}</strong>
          </div>
          <div class="brief-fact">
            <MapPin :size="18" aria-hidden="true" />
            <span>{{ t('campaignDetail.location') }}</span>
            <strong>{{ campaignPlace(campaign, locale) }}</strong>
          </div>
          <div class="brief-fact">
            <DollarSign :size="18" aria-hidden="true" />
            <span>{{ t('campaignDetail.budget') }}</span>
            <strong>{{ formatMoney(campaign.budgetMin, campaign.currency) }}–{{ formatMoney(campaign.budgetMax, campaign.currency) }}</strong>
          </div>
          <div class="brief-fact">
            <UsersRound :size="18" aria-hidden="true" />
            <span>{{ t('campaignDetail.spots') }}</span>
            <strong><CampaignHiringProgress :campaign="campaign" /></strong>
          </div>
          <div class="brief-fact">
            <CalendarDays :size="18" aria-hidden="true" />
            <span>{{ t('campaignDetail.closes') }}</span>
            <strong>{{ formatDate(campaign.closesAt) }}</strong>
          </div>
        </section>

        <section class="brief-company-card">
          <p class="eyebrow">{{ t('companyProfile.aboutEyebrow') }}</p>
          <div class="brief-company-card__heading">
            <span class="brand-avatar brand-avatar--large">
              <img v-if="campaign.company.logoUrl" :src="campaign.company.logoUrl" :alt="campaign.company.name">
              <WaveLogo v-else mark />
            </span>
            <div>
              <h2>
                {{ campaign.company.name }}
                <BadgeCheck
                  v-if="campaign.company.verified"
                  class="brief-hero__verified"
                  :size="15"
                  :aria-label="t('campaignCard.verified')"
                />
              </h2>
              <p>{{ campaign.company.industry }}</p>
            </div>
            <RouterLink
              class="brief-company-card__link"
              v-if="!campaign.company.deleted"
              :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }"
            >
              {{ t('companyDirectory.viewProfile') }} <ArrowRight :size="15" aria-hidden="true" />
            </RouterLink>
          </div>
          <RichTextContent v-if="campaign.company.about" :html="campaign.company.about" />
          <p v-else class="brief-company-card__empty">{{ t('companyProfile.profileLabel') }}</p>
        </section>
      </aside>
    </section>
  </template>
</template>

<style lang="scss" src="../scss/views/CampaignDetailView.scss"></style>
