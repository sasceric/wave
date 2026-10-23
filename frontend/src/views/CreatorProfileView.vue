<script setup>
import RegistrationPrompt from '../components/shared/RegistrationPrompt.vue'
import PublicShareButton from '../components/shared/PublicShareButton.vue'
import PresentationDemoBadge from '../components/shared/PresentationDemoBadge.vue'
import { useCreatorProfileVisit } from '../composables/useCreatorProfileVisit'
import MarketplaceSafetyActions from '../components/shared/MarketplaceSafetyActions.vue'
import ProfileReviews from '../components/reviews/ProfileReviews.vue'
import RatingSummary from '../components/reviews/RatingSummary.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import FormModal from '../components/shared/FormModal.vue'
import CreatorPresentationPreview from '../components/creators/CreatorPresentationPreview.vue'
import RichTextContent from '../components/shared/RichTextContent.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { Camera, Check, ChevronRight, CirclePlay, FileText, MapPin, MessageCircle, Music2, Plus, Send, X } from '@lucide/vue'
import ProfileFaqs from '../components/shared/ProfileFaqs.vue'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import ProfilePackages from '../components/shared/ProfilePackages.vue'
import CreatorWorks from '../components/creators/CreatorWorks.vue'
import CreatorAudience from '../components/creators/CreatorAudience.vue'
import { languageOptions } from '../lib/creatorPresentation'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { CREATOR_PLACEHOLDER } from '../lib/marketplace'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'

const route = useRoute()
const creator = ref(null)
useCreatorProfileVisit(creator)
const viewer = ref(null)
const error = ref('')
const requestDialog = ref(null)
const presentationOpen = ref(false)
const invitationOpen = ref(false)
const inviteLoading = ref(false)
const inviteSubmitted = ref(false)
const activeSection = ref('creator-works')
let profileGeneration = 0
const requestForm = ref({
  packageIds: [],
  other: false,
  message: '',
})
const requestBusy = ref(false)
const requestError = ref('')
const requestSubmitted = ref(false)
const viewerLoaded = ref(false)
const packageDescriptionOverflow = ref(new Set())
const inviteCampaigns = ref([])
const inviteForm = ref({ campaignSlug: '', message: '' })
const inviteBusy = ref(false)
const inviteError = ref('')
const expandedDescriptionIds = ref(new Set())
const packageDescriptionElements = new Map()
let packageDescriptionObserver = null
const { t, locale } = useI18n()
const { categories, error: catalogError } = useMarketplaceCatalog(locale)
const packagePlatformIcons = {
  Instagram: Camera,
  TikTok: Music2,
  YouTube: CirclePlay,
}

const canOpenRequest = computed(() => (
  viewerLoaded.value && (!viewer.value || viewer.value.accountType === 'company')
))

function campaignInviteStatus(campaign) {
  const creatorId = creator.value?.id
  if (creatorId == null) {
    return ''
  }
  if (campaign.invitedCreatorIds?.includes(creatorId)) {
    return t('campaignChat.alreadyInvited')
  }
  if (campaign.appliedCreatorIds?.includes(creatorId)) {
    return t('campaignChat.alreadyApplied')
  }

  return ''
}

const canSubmitCampaignInvitation = computed(() => {
  const campaign = inviteCampaigns.value.find(({ slug }) => slug === inviteForm.value.campaignSlug)

  return Boolean(campaign && !campaignInviteStatus(campaign))
})

const visiblePortfolio = computed(() => creator.value?.portfolio || [])

const profilePackages = computed(() => [
  ...(creator.value?.packages || []).map((packageItem) => ({
    ...packageItem,
    profileKey: `package-${packageItem.id}`,
    expansionId: `package-${packageItem.id}`,
    isNegotiationOption: false,
  })),
  {
    profileKey: 'negotiation-package-option',
    expansionId: 'negotiation-package-option',
    isNegotiationOption: true,
    title: t('creatorProfile.negotiatePackage'),
    description: t('creatorProfile.negotiatePackageDescription'),
  },
])

const sectionTabs = computed(() => [
  ...(visiblePortfolio.value.length ? [{ key: 'creator-works', label: t('studio.tabWorks') }] : []),
  ...(creator.value?.socialProfiles.length ? [{ key: 'creator-audience', label: t('studio.tabAudience') }] : []),
  { key: 'creator-packages', label: t('studio.tabPackages') },
  { key: 'creator-reviews', label: t('studio.tabReviews') },
  ...(profileFaqs.value.length ? [{ key: 'creator-faqs', label: t('studio.tabFaq') }] : []),
])

const categoryLabel = computed(() => (
  creator.value?.categoryLabel
  || categories.value.find(({ value }) => value === creator.value?.category)?.label
  || creator.value?.category
  || ''
))

const profileFaqs = computed(() => (
  (creator.value?.faqs || []).map((faq, index) => ({
    id: `profile-${index}`,
    question: faq.question,
    answer: faq.answer,
  }))
))

const canInvite = computed(() => viewer.value?.accountType === 'company'
  && viewer.value.emailVerified && viewer.value.approved && viewer.value.profileComplete !== false
  && creator.value?.canReceiveCampaignInvitations)

async function loadCreator() {
  const generation = ++profileGeneration
  requestDialog.value?.close()
  presentationOpen.value = false
  invitationOpen.value = false
  creator.value = null
  viewer.value = null
  viewerLoaded.value = false
  inviteCampaigns.value = []
  inviteError.value = ''
  inviteSubmitted.value = false
  inviteLoading.value = false
  activeSection.value = 'creator-works'
  error.value = ''
  requestError.value = ''
  requestSubmitted.value = false
  try {
    const response = await apiGet(`/creators/${encodeURIComponent(route.params.slug)}`)
    if (generation !== profileGeneration) return
    creator.value = response.data
    requestForm.value = { packageIds: [], other: false, message: '' }
    const session = await apiGet('/auth/session')
    if (generation !== profileGeneration) return
    viewer.value = session.data
    viewerLoaded.value = true
  } catch (cause) {
    if (generation !== profileGeneration) return
    error.value = cause.message
    if (cause.status === 404 && !creator.value) {
      updateSeo({ route: { ...route, meta: { ...route.meta, routeName: 'not-found' } }, locale: locale.value,
        title: t('seo.notFoundTitle'), description: t('seo.notFoundDescription'), noindex: true })
    }
  }
}

async function openCampaignInvitation() {
  invitationOpen.value = true
  inviteError.value = ''
  inviteSubmitted.value = false
  inviteForm.value = { campaignSlug: '', message: '' }
  if (!canInvite.value) return
  const generation = profileGeneration
  inviteLoading.value = true
  try {
    const response = await apiGet('/me/campaigns')
    if (generation !== profileGeneration) return
    inviteCampaigns.value = response.data.filter(campaign => campaign.status === 'open')
    inviteForm.value.campaignSlug = inviteCampaigns.value.find(campaign => !campaignInviteStatus(campaign))?.slug || ''
  } catch (cause) {
    if (generation === profileGeneration) inviteError.value = cause.message
  } finally {
    if (generation === profileGeneration) inviteLoading.value = false
  }
}

async function sendCampaignInvitation() {
  if (!canInvite.value || !canSubmitCampaignInvitation.value || inviteBusy.value) {
    return
  }

  const campaignSlug = inviteForm.value.campaignSlug
  inviteBusy.value = true
  inviteError.value = ''
  try {
    await apiRequest(
      `/company/campaigns/${encodeURIComponent(campaignSlug)}/invitations`,
      {
        method: 'POST',
        body: {
          creatorId: creator.value.id,
          message: inviteForm.value.message.trim(),
        },
      },
    )
    inviteCampaigns.value = inviteCampaigns.value.map((campaign) => (
      campaign.slug === campaignSlug
        ? {
            ...campaign,
            invitedCreatorIds: [...(campaign.invitedCreatorIds || []), creator.value.id],
          }
        : campaign
    ))
    inviteForm.value.campaignSlug = (
      inviteCampaigns.value.find((campaign) => !campaignInviteStatus(campaign))
      || inviteCampaigns.value.find(({ slug }) => slug === campaignSlug)
    )?.slug || ''
    inviteSubmitted.value = true
    inviteForm.value.message = ''
  } catch (cause) {
    inviteError.value = cause.message
  } finally {
    inviteBusy.value = false
  }
}

function openRequestForm(packageItem = null, preselectOther = false) {
  if (!canOpenRequest.value) {
    return
  }
  requestForm.value = {
    packageIds: packageItem ? [packageItem.id] : [],
    other: preselectOther,
    message: '',
  }
  requestError.value = ''
  requestSubmitted.value = false
  nextTick(() => {
    requestDialog.value?.showModal()
    nextTick(measurePackageDescriptions)
  })
}

function syncRequestCurrency() {
  const packageItem = requestForm.value.packageIds.length === 1
    ? creator.value?.packages.find(({ id }) => id === requestForm.value.packageIds[0])
    : null
  requestForm.value.currency = packageItem?.currency || 'BAM'
}

function toggleRequestPackage(packageId) {
  requestError.value = ''
  const selectedPackageIds = requestForm.value.packageIds
  requestForm.value.packageIds = selectedPackageIds.includes(packageId)
    ? selectedPackageIds.filter((selectedId) => selectedId !== packageId)
    : [...selectedPackageIds, packageId]
  syncRequestCurrency()
}

function toggleRequestOption(option) {
  requestError.value = ''
  requestForm.value[option] = !requestForm.value[option]
}

function toggleRequestPackageDescription(packageId) {
  togglePackageDescription(`request:${packageId}`)
}

function closeRequestForm() {
  if (!requestBusy.value && requestDialog.value?.open) {
    requestDialog.value.close()
  }
}

function handleRequestDialogCancel(event) {
  if (requestBusy.value) {
    event.preventDefault()
  }
}

function togglePackageDescription(packageId) {
  if (expandedDescriptionIds.value.has(packageId)) {
    expandedDescriptionIds.value.delete(packageId)
    nextTick(() => {
      const element = packageDescriptionElements.get(packageId)
      if (element) {
        updatePackageDescriptionOverflow(packageId, element)
      }
    })
    return
  }

  expandedDescriptionIds.value.add(packageId)
}

function updatePackageDescriptionOverflow(packageId, element) {
  if (expandedDescriptionIds.value.has(packageId)) {
    return
  }

  const hasOverflow = element.scrollWidth > element.clientWidth + 1
  const nextOverflow = new Set(packageDescriptionOverflow.value)
  if (hasOverflow) {
    nextOverflow.add(packageId)
  } else {
    nextOverflow.delete(packageId)
  }
  packageDescriptionOverflow.value = nextOverflow
}

function setPackageDescriptionElement(packageId, element) {
  const previousElement = packageDescriptionElements.get(packageId)
  if (previousElement === element) {
    return
  }
  if (previousElement) {
    packageDescriptionObserver?.unobserve(previousElement)
  }
  if (!element) {
    packageDescriptionElements.delete(packageId)
    const nextOverflow = new Set(packageDescriptionOverflow.value)
    nextOverflow.delete(packageId)
    packageDescriptionOverflow.value = nextOverflow
    return
  }

  packageDescriptionElements.set(packageId, element)
  packageDescriptionObserver?.observe(element)
  nextTick(() => {
    if (packageDescriptionElements.get(packageId) === element) {
      updatePackageDescriptionOverflow(packageId, element)
    }
  })
}

function measurePackageDescriptions() {
  for (const [packageId, element] of packageDescriptionElements) {
    updatePackageDescriptionOverflow(packageId, element)
  }
}

async function submitRequest() {
  if (
    requestForm.value.packageIds.length === 0
    && !requestForm.value.other
  ) {
    requestError.value = t('creatorProfile.chooseAtLeastOnePackage')
    return
  }

  const message = requestForm.value.message.trim()
  if (Array.from(message).length < 10) {
    requestError.value = t('creatorProfile.requestMessageTooShort')
    return
  }

  requestBusy.value = true
  requestError.value = ''
  try {
    await apiRequest(`/creators/${encodeURIComponent(creator.value.slug)}/inquiries`, {
      method: 'POST',
      body: {
        packageIds: requestForm.value.packageIds,
        servicePackage: false,
        other: requestForm.value.other,
        message,
      },
    })
    requestSubmitted.value = true
  } catch (cause) {
    requestError.value = cause.message
  } finally {
    requestBusy.value = false
  }
}

watch([() => route.params.slug, locale], loadCreator)
watch(canInvite, ready => { if (ready && invitationOpen.value && !inviteLoading.value) openCampaignInvitation() })
watch(profilePackages, () => nextTick(measurePackageDescriptions), { flush: 'post' })
watch(
  [creator, locale],
  ([value]) => {
    if (!value) return

    const image = value.avatarUrl ? new URL(value.avatarUrl, getSeoOrigin()).href : undefined
    const sameAs = value.socialProfiles
      .map((profile) => profile.url)
      .filter((url) => typeof url === 'string' && /^https?:\/\//i.test(url))
    updateSeo({
      route,
      locale: locale.value,
      title: t('seo.creatorProfileTitle', { name: value.displayName }),
      description: value.structuredData?.description || value.bio,
      image,
      noindex: value.indexable === false,
      mainEntity: value.structuredData || {
        '@type': 'Person',
        name: value.displayName,
        url: new URL(localizedPath('creator-profile', locale.value, { slug: value.slug }), getSeoOrigin()).href,
        description: value.structuredData?.description || value.bio,
        address: { '@type': 'PostalAddress', addressLocality: value.city },
        knowsAbout: value.categories,
        ...(sameAs.length ? { sameAs } : {}),
        ...(image ? { image } : {}),
      },
    })
  },
)
onMounted(() => {
  if (typeof ResizeObserver !== 'undefined') {
    packageDescriptionObserver = new ResizeObserver(measurePackageDescriptions)
    for (const element of packageDescriptionElements.values()) {
      packageDescriptionObserver.observe(element)
    }
  }
  loadCreator()
})
onBeforeUnmount(() => { ++profileGeneration; packageDescriptionObserver?.disconnect() })
</script>

<template>
  <section v-if="error && !creator" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/creators">
      {{ t('creatorProfile.back') }}
      ↗
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!creator" variant="profile" :label="t('creatorProfile.loading')" />
  <template v-else>
    <dialog
      ref="requestDialog"
      class="profile-request-dialog wave-modal wave-modal--wide"
      aria-labelledby="profile-request-title"
      @click.self="closeRequestForm"
      @cancel="handleRequestDialogCancel"
     aria-modal="true">
      <div class="profile-request-dialog__content wave-modal__content">
        <header class="profile-request-dialog__header">
          <div>
            <p class="eyebrow">{{ t('creatorProfile.requestEyebrow') }}</p>
            <h2 id="profile-request-title" class="wave-modal__title">{{ t('creatorProfile.requestTitle') }}</h2>
            <p>{{ t('creatorProfile.requestDescription') }}</p>
          </div>
          <button
            class="profile-request-dialog__close wave-modal__close"
            type="button"
            :aria-label="t('creatorProfile.closeRequest')"
            :disabled="requestBusy"
            @click="closeRequestForm"
          >
            <X :size="21" aria-hidden="true" />
          </button>
        </header>
        <StatusMessage v-if="requestError" variant="error">{{ requestError }}</StatusMessage>
        <StatusMessage v-if="requestSubmitted">{{ t('creatorProfile.requestSent') }}</StatusMessage>
        <button v-if="requestSubmitted" class="button button--dark" type="button" @click="closeRequestForm">
          {{ t('creatorProfile.closeRequest') }}
        </button>
        <div v-else-if="!viewer" class="profile-request__login">
          <p>{{ t('creatorProfile.companyOnly') }}</p>
          <RouterLink class="button button--dark" to="/account">
            {{ t('creatorProfile.signInAsCompany') }}
            <span aria-hidden="true">↗</span>
          </RouterLink>
        </div>
        <StatusMessage v-else-if="!viewer.approved" variant="error">
          {{ t('creatorProfile.accountPendingApproval') }}
        </StatusMessage>
        <StatusMessage v-else-if="!viewer.emailVerified" variant="error">
          {{ t('creatorProfile.verifyEmail') }}
        </StatusMessage>
        <form v-form-validation v-else class="profile-request-dialog__form" @submit.prevent="submitRequest">
          <fieldset class="profile-request-dialog__packages">
            <legend>{{ t('creatorProfile.choosePackages') }}</legend>
            <p>{{ t('creatorProfile.choosePackagesHint') }}</p>
            <article
              v-for="packageItem in creator.packages"
              :key="packageItem.id"
              class="profile-request-choice"
              :class="{ 'is-selected': requestForm.packageIds.includes(packageItem.id) }"
            >
              <div class="profile-request-choice__heading">
                <component :is="packagePlatformIcons[packageItem.platform] || Camera" :size="23" aria-hidden="true" />
                <h3>{{ packageItem.title }}</h3>
                <strong>
                  {{ packageItem.priceMode === 'from' ? t('studio.priceFrom') : '' }}
                  {{ packageItem.price ? formatMoney(packageItem.price, packageItem.currency) : t('creatorProfile.priceOnRequest') }}
                </strong>
                <button
                  class="profile-request-choice__toggle"
                  type="button"
                  :aria-pressed="requestForm.packageIds.includes(packageItem.id)"
                  :aria-label="t(requestForm.packageIds.includes(packageItem.id) ? 'creatorProfile.removeSelection' : 'creatorProfile.addSelection', { package: packageItem.title })"
                  @click="toggleRequestPackage(packageItem.id)"
                >
                  <Check v-if="requestForm.packageIds.includes(packageItem.id)" :size="19" aria-hidden="true" />
                  <Plus v-else :size="19" aria-hidden="true" />
                </button>
              </div>
              <div class="profile-request-choice__description-row">
                <p
                  :ref="(element) => setPackageDescriptionElement(`request:${packageItem.id}`, element)"
                  class="profile-request-choice__description"
                  :class="{ 'is-expanded': expandedDescriptionIds.has(`request:${packageItem.id}`) }"
                >
                  {{ packageItem.description }}
                </p>
                <button
                  v-if="packageDescriptionOverflow.has(`request:${packageItem.id}`)"
                  class="profile-request-choice__more"
                  type="button"
                  :aria-expanded="expandedDescriptionIds.has(`request:${packageItem.id}`)"
                  @click="toggleRequestPackageDescription(packageItem.id)"
                >
                  {{ t(expandedDescriptionIds.has(`request:${packageItem.id}`) ? 'creatorProfile.showLess' : 'creatorProfile.showMore') }}
                </button>
              </div>
            </article>
            <article class="profile-request-choice" :class="{ 'is-selected': requestForm.other }">
              <div class="profile-request-choice__heading profile-request-choice__heading--no-price">
                <MessageCircle :size="23" aria-hidden="true" />
                <h3>{{ t('creatorProfile.somethingElse') }}</h3>
                <button
                  class="profile-request-choice__toggle"
                  type="button"
                  :aria-pressed="requestForm.other"
                  :aria-label="t(requestForm.other ? 'creatorProfile.removeSelection' : 'creatorProfile.addSelection', { package: t('creatorProfile.somethingElse') })"
                  @click="toggleRequestOption('other')"
                >
                  <Check v-if="requestForm.other" :size="19" aria-hidden="true" />
                  <Plus v-else :size="19" aria-hidden="true" />
                </button>
              </div>
              <p class="profile-request-choice__description profile-request-choice__description--full">
                {{ t('creatorProfile.somethingElseDescription') }}
              </p>
            </article>
          </fieldset>
          <label class="form-field">
            <span>{{ t('creatorProfile.requestMessage') }}</span>
            <textarea v-model.trim="requestForm.message" required minlength="10" maxlength="2000"></textarea>
          </label>
          <button class="button button--dark" type="submit" :disabled="requestBusy">
            {{ requestBusy ? t('campaignChat.sending') : t('creatorProfile.sendRequest') }}
            <span aria-hidden="true">↗</span>
          </button>
        </form>
      </div>
    </dialog>
    <FormModal :open="presentationOpen" :title="t('studio.presentationTitle')" wide @update:open="presentationOpen = $event">
      <CreatorPresentationPreview :profile="creator" />
      <template #actions="{ close }">
        <button class="button button--outline" type="button" @click="close">{{ t('reviews.close') }}</button>
      </template>
    </FormModal>
    <FormModal
      :open="invitationOpen"
      :title="t('creatorProfile.inviteAction')"
      :description="t('campaignChat.inviteDescription')"
      :busy="inviteBusy"
      @update:open="invitationOpen = $event"
      @submit="sendCampaignInvitation"
    >
      <p class="profile-invitation-target">{{ creator.displayName }}</p>
      <StatusMessage v-if="!viewerLoaded">{{ t('app.loading') }}</StatusMessage>
      <template v-else-if="!viewer">
        <p>{{ t('campaignChat.companyOnlyStart') }}</p>
        <RouterLink class="button button--dark" :to="{ name: 'login' }">
          {{ t('creatorProfile.signInAsCompany') }}
        </RouterLink>
      </template>
      <StatusMessage v-else-if="viewer.accountType !== 'company'" variant="empty">
        {{ t('campaignChat.companyOnlyStart') }}
      </StatusMessage>
      <StatusMessage v-else-if="!viewer.approved" variant="empty">{{ t('creatorProfile.accountPendingApproval') }}</StatusMessage>
      <StatusMessage v-else-if="!viewer.emailVerified" variant="empty">{{ t('creatorProfile.verifyEmail') }}</StatusMessage>
      <StatusMessage v-else-if="!canInvite" variant="empty">{{ t('creatorProfile.inviteUnavailable') }}</StatusMessage>
      <StatusMessage v-else-if="inviteSubmitted">{{ t('campaignChat.invitationSent') }}</StatusMessage>
      <p v-else-if="inviteLoading" role="status">{{ t('app.loading') }}</p>
      <template v-else>
        <StatusMessage v-if="inviteError" variant="error">{{ inviteError }}</StatusMessage>
        <template v-if="inviteCampaigns.length">
          <SingleSelect
            v-model="inviteForm.campaignSlug"
            :label="t('campaignChat.chooseCampaign')"
            :options="inviteCampaigns.map(campaign => ({ value: campaign.slug, label: campaign.title + (campaignInviteStatus(campaign) ? ` — ${campaignInviteStatus(campaign)}` : ''), disabled: Boolean(campaignInviteStatus(campaign)) }))"
            required
          />
          <label class="form-field">
            <span>{{ t('campaignChat.invitationMessage') }}</span>
            <textarea v-model.trim="inviteForm.message" required minlength="1" maxlength="2000"></textarea>
          </label>
        </template>
        <template v-else>
          <StatusMessage variant="empty">{{ t('campaignChat.noInviteCampaigns') }}</StatusMessage>
          <RouterLink class="text-link" :to="{ name: 'account-campaigns' }">{{ t('account.campaigns') }} ↗</RouterLink>
        </template>
      </template>
      <template #actions="{ close }">
        <button class="button button--outline" type="button" :disabled="inviteBusy" @click="close">{{ t('reviews.close') }}</button>
        <button
          v-if="canInvite && !inviteSubmitted && inviteCampaigns.length && !inviteLoading"
          class="button button--dark"
          type="submit"
          :disabled="inviteBusy || !canSubmitCampaignInvitation"
        >
          <Send :size="16" aria-hidden="true" />
          {{ t(inviteBusy ? 'campaignChat.sending' : 'campaignChat.inviteButton') }}
        </button>
      </template>
    </FormModal>
    <section class="creator-profile">
      <section class="profile-layout page-width">
        <nav class="profile-breadcrumbs" :aria-label="t('creatorProfile.breadcrumbs')">
          <RouterLink :to="{ name: 'creators' }">{{ t('app.creatorsNav') }}</RouterLink>
          <ChevronRight :size="14" aria-hidden="true" />
          <span aria-current="page">{{ creator.displayName }}</span>
          <PresentationDemoBadge v-if="creator.isPresentationDemo" />
          <PublicShareButton route-name="creator-profile" :slug="creator.slug" :title="creator.displayName" />
        </nav>
        <header class="profile-heading">
          <div class="profile-heading__identity">
            <div class="profile-heading__avatar">
              <img :src="creator.avatarUrl || CREATOR_PLACEHOLDER" :alt="creator.displayName" />
            </div>
            <div class="profile-heading__copy">
              <p class="eyebrow">{{ categoryLabel }}</p>
              <h1>{{ creator.displayName }}</h1>
              <p v-if="creator.tagline" class="profile-tagline">{{ creator.tagline }}</p>
              <div class="profile-heading__meta">
                <span v-if="creator.city">
                  <MapPin :size="15" aria-hidden="true" />
                  {{ creator.city }}
                </span>
                <RatingSummary :rating="creator.rating" profile />
              </div>
              <div v-if="creator.creatorTypes?.length || creator.presentation?.languages?.length" class="profile-presentation-pills">
                <span v-for="type in creator.creatorTypes" :key="type">{{ t(`creatorTypes.options.${type}`) }}</span>
                <span v-for="code in creator.presentation?.languages || []" :key="code">
                  {{ languageOptions(locale, t).find(item => item.value === code)?.label || code }}
                </span>
              </div>
              <a
                v-if="creator.presentation?.introVideoUrl"
                class="profile-intro-video"
                :href="creator.presentation.introVideoUrl"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ t('studio.introVideo') }}
                ↗
              </a>
            </div>
          </div>
          <div class="profile-heading__actions">
            <button class="button button--outline" type="button" @click="presentationOpen = true">
              <FileText :size="16" aria-hidden="true" />
              {{ t('studio.presentationTitle') }}
            </button>
            <button class="button button--dark" type="button" @click="openCampaignInvitation">
              <Send :size="16" aria-hidden="true" />
              {{ t('creatorProfile.inviteAction') }}
            </button>
            <span
              v-if="creator.presentation?.availability && creator.presentation.availability !== 'unspecified'"
              class="profile-heading__availability"
              :class="{
                'profile-heading__availability--busy': creator.presentation.availability === 'busy',
                'profile-heading__availability--scheduled': creator.presentation.availability === 'from',
              }"
            >
              <span class="profile-heading__availability-dot" aria-hidden="true"></span>
              <span>{{ t(`studio.${creator.presentation.availability}`) }}
              <template v-if="creator.presentation.availability === 'from'"> · {{ formatDate(creator.presentation.availableFrom) }}</template></span>
            </span>
          </div>
        </header>
        <ProfileTabs v-model="activeSection" :items="sectionTabs" :label="t('studio.profileSections')" sections />
        <div class="profile-column">
          <div class="profile-main">
            <StatusMessage v-if="catalogError" variant="error">{{ catalogError }}</StatusMessage>
            <CreatorWorks
              id="creator-works"
              v-if="visiblePortfolio.length"
              class="profile-portfolio"
              :items="visiblePortfolio"
              :name="creator.displayName"
            />
          </div>
          <CreatorAudience id="creator-audience" class="profile-audience" :demo="creator.isPresentationDemo" :profiles="creator.socialProfiles" :languages="creator.presentation?.languages || []" :description="creator.tagline || ''" />
          <MarketplaceSafetyActions kind="creator" :slug="creator.slug" />
          <ProfileReviews id="creator-reviews" kind="creators" :slug="creator.slug" />
        </div>
        <div class="profile-sidebar">
          <RegistrationPrompt audience="company" variant="profile" :return-to="localizedPath('creator-profile', locale, { slug: creator.slug })" />
          <ProfilePackages id="creator-packages" :items="profilePackages" :can-request="canOpenRequest" @request="openRequestForm($event.isNegotiationOption ? null : $event, $event.isNegotiationOption)" />
          <section v-if="creator.bio || creator.tags.length" class="profile-about">
            <h2>{{ t('studio.aboutCreator') }}</h2>
            <RichTextContent class="profile-bio" :html="creator.bio" />
            <div class="profile-tags">
              <span v-for="tag in creator.tags" :key="tag">{{ tag }}</span>
            </div>
          </section>
          <div class="profile-sidebar-details">
            <ProfileFaqs
              id="creator-faqs"
              v-if="profileFaqs.length"
              class="profile-content-section profile-faqs"
              :items="profileFaqs"
            />
          </div>
        </div>
      </section>
    </section>
  </template>
</template>
<style lang="scss" src="../scss/views/CreatorProfileView.scss"></style>
