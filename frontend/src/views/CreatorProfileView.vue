<script setup>
import RichTextContent from '../components/shared/RichTextContent.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { Camera, Check, CirclePlay, MapPin, MessageCircle, Music2, Plus, X } from '@lucide/vue'
import FaqSection from '../components/shared/FaqSection.vue'
import PortfolioGallery from '../components/shared/PortfolioGallery.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { apiGet, apiRequest, formatDate, formatFollowers, formatMoney } from '../lib/api'
import { CREATOR_PLACEHOLDER, CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'

const route = useRoute()
const creator = ref(null)
const viewer = ref(null)
const error = ref('')
const notice = ref('')
const selectedPlatform = ref('All')
const requestDialog = ref(null)
const requestForm = ref({
  packageIds: [],
  other: false,
  proposedAmount: '',
  currency: 'BAM',
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

const availablePlatforms = computed(() => {
  if (!creator.value) {
    return ['All']
  }
  const usedPlatforms = [
    ...creator.value.socialProfiles.map(({ platform }) => platform),
    ...creator.value.packages.map(({ platform }) => platform),
    ...creator.value.portfolio.map(({ platform }) => platform),
  ]

  return ['All', ...SOCIAL_PLATFORMS.filter((platform) => usedPlatforms.includes(platform))]
})

const visiblePortfolio = computed(() => {
  if (!creator.value) {
    return []
  }
  if (selectedPlatform.value === 'All') {
    return creator.value.portfolio
  }

  return creator.value.portfolio.filter((item) => item.platform === 'All' || item.platform === selectedPlatform.value)
})


const visiblePackages = computed(() => {
  if (!creator.value) {
    return []
  }
  if (selectedPlatform.value === 'All') {
    return creator.value.packages
  }

  return creator.value.packages.filter((item) => item.platform === selectedPlatform.value)
})

const profilePackages = computed(() => [
  ...visiblePackages.value.map((packageItem) => ({
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

async function loadCreator() {
  if (requestDialog.value?.open) {
    requestDialog.value.close()
  }
  creator.value = null
  viewer.value = null
  viewerLoaded.value = false
  inviteCampaigns.value = []
  inviteError.value = ''
  selectedPlatform.value = 'All'
  error.value = ''
  notice.value = ''
  requestError.value = ''
  requestSubmitted.value = false
  try {
    const response = await apiGet(`/creators/${encodeURIComponent(route.params.slug)}`)
    creator.value = response.data
    requestForm.value = {
      packageIds: [],
      other: false,
      proposedAmount: '',
      currency: 'BAM',
      message: '',
    }

    try {
      const currentUser = await apiGet('/auth/me')
      viewer.value = currentUser.data
      viewerLoaded.value = true
      if (viewer.value.accountType === 'company' && viewer.value.emailVerified && viewer.value.approved) {
        const campaignResponse = await apiGet('/me/campaigns')
        inviteCampaigns.value = campaignResponse.data.filter((campaign) => (
          campaign.status === 'open'
        ))
        inviteForm.value.campaignSlug = (
          inviteCampaigns.value.find((campaign) => !campaignInviteStatus(campaign))
          || inviteCampaigns.value[0]
        )?.slug || ''
      }
    } catch (cause) {
      if (cause.status === 401) {
        viewer.value = null
        viewerLoaded.value = true
      } else {
        throw cause
      }
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function sendCampaignInvitation() {
  if (!canSubmitCampaignInvitation.value) {
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
    notice.value = t('campaignChat.invitationSent')
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
    proposedAmount: '',
    currency: packageItem?.currency || 'BAM',
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

function selectPlatform(platform) {
  selectedPlatform.value = platform
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
        proposedAmount: requestForm.value.proposedAmount === '' ? null : Number(requestForm.value.proposedAmount),
        currency: requestForm.value.currency,
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
      description: value.bio,
      image,
      mainEntity: {
        '@type': 'Person',
        name: value.displayName,
        url: new URL(localizedPath('creator-profile', locale.value, { slug: value.slug }), getSeoOrigin()).href,
        description: value.bio,
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
onBeforeUnmount(() => packageDescriptionObserver?.disconnect())
</script>

<template>
  <section v-if="error && !creator" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/creators">
      {{ t('creatorProfile.back') }} ↗
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!creator" variant="profile" :label="t('creatorProfile.loading')" />
  <template v-else>
    <dialog
      ref="requestDialog"
      class="profile-request-dialog"
      aria-labelledby="profile-request-title"
      @click.self="closeRequestForm"
      @cancel="handleRequestDialogCancel"
    >
      <div class="profile-request-dialog__content">
        <header class="profile-request-dialog__header">
          <div>
            <p class="eyebrow">{{ t('creatorProfile.requestEyebrow') }}</p>
            <h2 id="profile-request-title">{{ t('creatorProfile.requestTitle') }}</h2>
            <p>{{ t('creatorProfile.requestDescription') }}</p>
          </div>
          <button
            class="profile-request-dialog__close"
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
        <button
          v-if="requestSubmitted"
          class="button button--dark"
          type="button"
          @click="closeRequestForm"
        >
          {{ t('creatorProfile.closeRequest') }}
        </button>
        <div v-else-if="!viewer" class="profile-request__login">
          <p>{{ t('creatorProfile.companyOnly') }}</p>
          <RouterLink class="button button--dark" to="/account">
            {{ t('creatorProfile.signInAsCompany') }} <span aria-hidden="true">↗</span>
          </RouterLink>
        </div>
        <StatusMessage v-else-if="!viewer.approved" variant="error">
          {{ t('creatorProfile.accountPendingApproval') }}
        </StatusMessage>
        <StatusMessage v-else-if="!viewer.emailVerified" variant="error">
          {{ t('creatorProfile.verifyEmail') }}
        </StatusMessage>
        <form v-else class="profile-request-dialog__form" @submit.prevent="submitRequest">
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
                <strong>{{ packageItem.price ? formatMoney(packageItem.price, packageItem.currency) : t('creatorProfile.priceOnRequest') }}</strong>
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
          <div class="form-grid">
            <label class="form-field">
              <span>{{ t('creatorProfile.proposedAmount') }}</span>
              <input v-model="requestForm.proposedAmount" type="number" min="1" max="10000000" />
            </label>
            <label class="form-field">
              <span>{{ t('account.currency') }}</span>
              <select v-model="requestForm.currency">
                <option v-for="currency in CURRENCIES" :key="currency" :value="currency">
                  {{ currency }}
                </option>
              </select>
            </label>
          </div>
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
    <section class="creator-profile">
      <div class="creator-profile__backdrop" aria-hidden="true"><span></span><span></span><span></span></div>
      <section class="profile-layout page-width">
        <div class="profile-main">
          <div class="profile-heading">
            <div class="profile-heading__identity">
              <div class="profile-heading__avatar">
                <img :src="creator.avatarUrl || CREATOR_PLACEHOLDER" :alt="creator.displayName" />
              </div>
              <div>
                <p class="eyebrow">{{ categoryLabel }}<template v-if="creator.city"> · {{ creator.city }}</template></p>
                <h1>{{ creator.displayName }}</h1>
                <p class="profile-tagline">{{ creator.tagline }}</p>
                <div
                  v-if="creator.socialProfiles.length || creator.city"
                  class="profile-stat-pills"
                  role="group"
                  :aria-label="t('creatorProfile.mediaKit')"
                >
                  <span
                    v-for="profile in creator.socialProfiles"
                    :key="profile.platform"
                    :title="t('creatorProfile.updated', { date: formatDate(profile.lastUpdated) })"
                    :aria-label="`${formatFollowers(profile.followers)} ${profile.platform}. ${t('creatorProfile.updated', { date: formatDate(profile.lastUpdated) })}. ${t('creatorProfile.selfReported')}`"
                  >
                    <component :is="packagePlatformIcons[profile.platform] || Camera" :size="19" aria-hidden="true" />
                    <strong>{{ formatFollowers(profile.followers) }}</strong>
                  </span>
                  <span v-if="creator.city"><MapPin :size="18" aria-hidden="true" />{{ creator.city }}</span>
                </div>
              </div>
            </div>
          </div>
          <RichTextContent :html="creator.bio" />
          <div class="profile-tags"><span v-for="tag in creator.tags" :key="tag">{{ tag }}</span></div>
          <StatusMessage v-if="catalogError" variant="error">{{ catalogError }}</StatusMessage>

          <PortfolioGallery v-if="visiblePortfolio.length" class="profile-portfolio" :items="visiblePortfolio" :name="creator.displayName" />
        </div>
        <aside class="profile-aside">
          <p class="eyebrow">{{ t('creatorProfile.goodFit') }}</p>
          <h3>{{ t('creatorProfile.brandsCategory', { category: categoryLabel.toLowerCase() }) }}</h3>
          <p>{{ t('creatorProfile.goodFitDescription') }}</p>
          <button
            v-if="canOpenRequest"
            class="button button--dark button--full"
            type="button"
            @click="openRequestForm()"
          >
            {{ t('creatorProfile.requestCollaboration') }} <span aria-hidden="true">↗</span>
          </button>
          <p v-if="canOpenRequest" class="profile-aside__note">{{ t('creatorProfile.chatNote') }}</p>
          <form
            v-if="viewer?.accountType === 'company' && viewer.emailVerified && viewer.approved && creator.canReceiveCampaignInvitations"
            class="campaign-invite-form"
            @submit.prevent="sendCampaignInvitation"
          >
            <h4>{{ t('campaignChat.inviteTitle') }}</h4>
            <p>{{ t('campaignChat.inviteDescription') }}</p>
            <StatusMessage v-if="inviteError" variant="error">{{ inviteError }}</StatusMessage>
            <StatusMessage v-if="notice">{{ notice }}</StatusMessage>
            <template v-if="inviteCampaigns.length">
              <label class="form-field">
                <span>{{ t('campaignChat.chooseCampaign') }}</span>
                <select v-model="inviteForm.campaignSlug" required>
                  <option value="" disabled>{{ t('campaignChat.chooseCampaign') }}</option>
                  <option
                    v-for="campaign in inviteCampaigns"
                    :key="campaign.id"
                    :value="campaign.slug"
                    :disabled="Boolean(campaignInviteStatus(campaign))"
                  >
                    {{ campaign.title }}{{ campaignInviteStatus(campaign) ? ` — ${campaignInviteStatus(campaign)}` : '' }}
                  </option>
                </select>
              </label>
              <label class="form-field">
                <span>{{ t('campaignChat.invitationMessage') }}</span>
                <textarea v-model.trim="inviteForm.message" required minlength="1" maxlength="2000"></textarea>
              </label>
              <button
                class="button button--outline button--full"
                type="submit"
                :disabled="inviteBusy || !canSubmitCampaignInvitation"
              >
                {{ inviteBusy ? t('campaignChat.sending') : t('campaignChat.inviteButton') }}
              </button>
            </template>
            <StatusMessage v-else variant="empty">{{ t('campaignChat.noInviteCampaigns') }}</StatusMessage>
          </form>
        </aside>
        <section class="profile-content-section profile-packages">
          <div class="profile-section-heading">
            <div><p class="eyebrow">{{ t('creatorProfile.collaborateEyebrow') }}</p><h2>{{ t('creatorProfile.packages') }}</h2></div>
          </div>
          <div class="profile-platform-tabs" role="tablist" :aria-label="t('creatorProfile.platforms')">
            <button
              v-for="platform in availablePlatforms"
              :key="platform"
              class="profile-platform-tabs__item"
              :class="{ 'is-active': selectedPlatform === platform }"
              type="button"
              role="tab"
              :aria-selected="selectedPlatform === platform"
              @click="selectPlatform(platform)"
            >
              {{ platform === 'All' ? t('creatorProfile.allPlatforms') : platform }}
            </button>
          </div>
          <div class="profile-package-grid">
            <article
              v-for="packageItem in profilePackages"
              :key="packageItem.profileKey"
              class="profile-package"
              :class="{ 'profile-package--negotiation': packageItem.isNegotiationOption }"
            >
              <div class="profile-package__details">
                <div
                  class="profile-package__heading"
                  :class="{ 'profile-package__heading--no-action': !canOpenRequest }"
                >
                  <component
                    :is="packageItem.isNegotiationOption ? MessageCircle : (packagePlatformIcons[packageItem.platform] || Camera)"
                    :size="23"
                    aria-hidden="true"
                  />
                  <h3>{{ packageItem.title }}</h3>
                  <strong>
                    {{ packageItem.isNegotiationOption
                      ? t('creatorProfile.priceByAgreement')
                      : !packageItem.price
                        ? t('creatorProfile.priceOnRequest')
                      : formatMoney(packageItem.price, packageItem.currency) }}
                  </strong>
                  <button
                    v-if="canOpenRequest"
                    class="profile-package__request"
                    type="button"
                    :aria-label="`${t('creatorProfile.requestPackage')}: ${packageItem.title}`"
                    @click="openRequestForm(packageItem.isNegotiationOption ? null : packageItem, packageItem.isNegotiationOption)"
                  >
                    <Plus :size="20" aria-hidden="true" />
                  </button>
                </div>
                <div class="profile-package__description-row">
                  <p
                    :ref="(element) => setPackageDescriptionElement(`profile:${packageItem.expansionId}`, element)"
                    :class="{ 'is-expanded': expandedDescriptionIds.has(`profile:${packageItem.expansionId}`) }"
                  >
                    {{ packageItem.description }}
                  </p>
                  <button
                    v-if="packageDescriptionOverflow.has(`profile:${packageItem.expansionId}`)"
                    class="profile-package__toggle"
                    type="button"
                    :aria-expanded="expandedDescriptionIds.has(`profile:${packageItem.expansionId}`)"
                    @click="togglePackageDescription(`profile:${packageItem.expansionId}`)"
                  >
                    {{ t(expandedDescriptionIds.has(`profile:${packageItem.expansionId}`) ? 'creatorProfile.showLess' : 'creatorProfile.showMore') }}
                  </button>
                </div>
              </div>
            </article>
          </div>
        </section>

        <div class="profile-sidebar-details">
          <FaqSection
            v-if="profileFaqs.length"
            class="profile-content-section profile-faqs"
            :eyebrow="t('creatorProfile.faqEyebrow')"
            :title="t('creatorProfile.faqTitle')"
            :items="profileFaqs"
          />
          <section
            v-if="creator.categoryLabels.length"
            class="profile-content-section profile-related-categories"
          >
            <div class="profile-section-heading">
              <h2>{{ t('creatorProfile.relatedCategories') }}</h2>
            </div>
            <div class="profile-related-categories__items">
              <span v-for="category in creator.categoryLabels" :key="category">{{ category }}</span>
            </div>
          </section>
        </div>
      </section>
    </section>
  </template>
</template>

<style lang="scss" src="../scss/views/CreatorProfileView.scss"></style>
