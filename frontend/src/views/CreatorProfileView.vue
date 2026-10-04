<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, X } from '@lucide/vue'
import FaqSection from '../components/shared/FaqSection.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { apiGet, apiRequest, formatDate, formatFollowers, formatMoney } from '../lib/api'
import { CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'
import { sanitizeRichText } from '../lib/richText'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath, localizedRouteName } from '../routePaths'

const route = useRoute()
const router = useRouter()
const creator = ref(null)
const viewer = ref(null)
const error = ref('')
const notice = ref('')
const copied = ref(false)
const selectedPlatform = ref('All')
const currentPortfolioIndex = ref(0)
const lightboxIndex = ref(0)
const mobileGallery = ref(null)
const portfolioLightbox = ref(null)
const portfolioGalleryDialog = ref(null)
const requestForm = ref({ packageId: '', proposedAmount: '', currency: 'BAM', message: '' })
const requestBusy = ref(false)
const inviteCampaigns = ref([])
const inviteForm = ref({ campaignSlug: '', message: '' })
const inviteBusy = ref(false)
const inviteError = ref('')
const expandedPackageIds = ref(new Set())
const { t, locale } = useI18n()
const { categories, faqs, error: catalogError } = useMarketplaceCatalog(locale, { includeFaqs: true })

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

const featuredPortfolio = computed(() => visiblePortfolio.value.slice(0, 3))
const lightboxItem = computed(() => visiblePortfolio.value[lightboxIndex.value] || null)

const visiblePackages = computed(() => {
  if (!creator.value) {
    return []
  }
  if (selectedPlatform.value === 'All') {
    return creator.value.packages
  }

  return creator.value.packages.filter((item) => item.platform === selectedPlatform.value)
})

const categoryLabel = computed(() => (
  creator.value?.categoryLabel
  || categories.value.find(({ value }) => value === creator.value?.category)?.label
  || creator.value?.category
  || ''
))

const profileFaqs = computed(() => [
  ...(creator.value?.faqs || []).map((faq, index) => ({
    id: `profile-${index}`,
    question: faq.question,
    answer: faq.answer,
  })),
  ...faqs.value.map((faq) => ({
    id: `shared-${faq.id}`,
    question: faq.question,
    answer: faq.answer,
  })),
])

async function loadCreator() {
  creator.value = null
  viewer.value = null
  inviteCampaigns.value = []
  inviteError.value = ''
  selectedPlatform.value = 'All'
  currentPortfolioIndex.value = 0
  lightboxIndex.value = 0
  closeLightbox()
  closePortfolioGallery()
  error.value = ''
  notice.value = ''
  try {
    const response = await apiGet(`/creators/${encodeURIComponent(route.params.slug)}`)
    creator.value = response.data
    requestForm.value = { packageId: '', proposedAmount: '', currency: 'BAM', message: '' }

    try {
      const currentUser = await apiGet('/auth/me')
      viewer.value = currentUser.data
      if (viewer.value.accountType === 'company' && viewer.value.emailVerified && viewer.value.approved) {
        const campaignResponse = await apiGet('/me/campaigns')
        inviteCampaigns.value = campaignResponse.data.filter((campaign) => (
          campaign.status === 'open'
        ))
        inviteForm.value.campaignSlug = inviteCampaigns.value[0]?.slug || ''
      }
    } catch (cause) {
      if (cause.status === 401) {
        viewer.value = null
      } else {
        throw cause
      }
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function sendCampaignInvitation() {
  inviteBusy.value = true
  inviteError.value = ''
  try {
    const response = await apiRequest(
      `/company/campaigns/${encodeURIComponent(inviteForm.value.campaignSlug)}/invitations`,
      {
        method: 'POST',
        body: {
          creatorId: creator.value.id,
          message: inviteForm.value.message.trim(),
        },
      },
    )
    await router.push({
      name: localizedRouteName('messages', locale.value),
      query: { conversation: response.data.id },
    })
  } catch (cause) {
    inviteError.value = cause.message
  } finally {
    inviteBusy.value = false
  }
}

function openRequestForm(packageItem = null) {
  requestForm.value = {
    packageId: packageItem?.id || '',
    proposedAmount: '',
    currency: packageItem?.currency || 'BAM',
    message: '',
  }
  document.querySelector('.profile-request')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

function syncRequestCurrency() {
  const packageItem = creator.value?.packages.find(({ id }) => id === requestForm.value.packageId)
  requestForm.value.currency = packageItem?.currency || 'BAM'
}

function selectPlatform(platform) {
  selectedPlatform.value = platform
  currentPortfolioIndex.value = 0
  lightboxIndex.value = 0
  nextTick(() => mobileGallery.value?.scrollTo({ left: 0, behavior: 'smooth' }))
}

function updateCurrentPortfolioIndex() {
  const gallery = mobileGallery.value
  if (!gallery) {
    return
  }

  const slides = Array.from(gallery.children)
  const galleryLeft = gallery.getBoundingClientRect().left + gallery.clientLeft
  currentPortfolioIndex.value = slides.reduce((nearestIndex, slide, index) => (
    Math.abs(slide.getBoundingClientRect().left - galleryLeft)
      < Math.abs(slides[nearestIndex].getBoundingClientRect().left - galleryLeft)
      ? index
      : nearestIndex
  ), 0)
}

function scrollToPortfolioItem(index) {
  const gallery = mobileGallery.value
  const slide = gallery?.children[index]
  if (!slide) {
    return
  }

  currentPortfolioIndex.value = index
  const galleryLeft = gallery.getBoundingClientRect().left + gallery.clientLeft
  const slideLeft = slide.getBoundingClientRect().left
  gallery.scrollTo({
    left: gallery.scrollLeft + slideLeft - galleryLeft,
    behavior: 'smooth',
  })
}

function openLightbox(index = 0) {
  closePortfolioGallery()
  lightboxIndex.value = index
  nextTick(() => portfolioLightbox.value?.showModal())
}

function closeLightbox() {
  if (portfolioLightbox.value?.open) {
    portfolioLightbox.value.close()
  }
}

function openPortfolioGallery() {
  portfolioGalleryDialog.value?.showModal()
}

function closePortfolioGallery() {
  if (portfolioGalleryDialog.value?.open) {
    portfolioGalleryDialog.value.close()
  }
}

function moveLightbox(direction) {
  const count = visiblePortfolio.value.length
  if (count > 0) {
    lightboxIndex.value = (lightboxIndex.value + direction + count) % count
  }
}

function togglePackageDescription(packageId) {
  if (expandedPackageIds.value.has(packageId)) {
    expandedPackageIds.value.delete(packageId)
    return
  }

  expandedPackageIds.value.add(packageId)
}

async function submitRequest() {
  requestBusy.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/creators/${encodeURIComponent(creator.value.slug)}/inquiries`, {
      method: 'POST',
      body: {
        packageId: requestForm.value.packageId || null,
        proposedAmount: requestForm.value.proposedAmount === '' ? null : Number(requestForm.value.proposedAmount),
        currency: requestForm.value.currency,
        message: requestForm.value.message.trim(),
      },
    })
    notice.value = t('creatorProfile.requestSent')
    requestForm.value.message = ''
  } catch (cause) {
    error.value = cause.message
  } finally {
    requestBusy.value = false
  }
}

async function shareProfile() {
  const url = window.location.href
  try {
    if (navigator.share) {
      await navigator.share({ title: t('creatorProfile.shareTitle', { name: creator.value.displayName }), url })
    } else {
      await navigator.clipboard.writeText(url)
      copied.value = true
      setTimeout(() => (copied.value = false), 1800)
    }
  } catch (cause) {
    if (cause.name !== 'AbortError') {
      error.value = t('creatorProfile.shareError')
    }
  }
}

watch([() => route.params.slug, locale], loadCreator)
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
        address: { '@type': 'PostalAddress', addressLocality: value.location },
        knowsAbout: value.categories,
        ...(sameAs.length ? { sameAs } : {}),
        ...(image ? { image } : {}),
      },
    })
  },
)
onMounted(loadCreator)
</script>

<template>
  <section v-if="error && !creator" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/creators">
      {{ t('creatorProfile.back') }} ↗
    </RouterLink>
  </section>
  <section v-else-if="!creator" class="page-width profile-error">
    <StatusMessage>{{ t('creatorProfile.loading') }}</StatusMessage>
  </section>
  <template v-else>
    <dialog
      ref="portfolioGalleryDialog"
      class="portfolio-gallery-dialog"
      :aria-label="t('creatorProfile.portfolio')"
      @click.self="closePortfolioGallery"
    >
      <div class="portfolio-gallery-dialog__content">
        <header class="portfolio-gallery-dialog__header">
          <div>
            <p class="eyebrow">{{ t('creatorProfile.workEyebrow') }}</p>
            <h2>{{ t('creatorProfile.portfolio') }}</h2>
            <span>{{ t('creatorProfile.portfolioCount', { count: visiblePortfolio.length }) }}</span>
          </div>
          <button
            class="portfolio-gallery-dialog__close"
            type="button"
            :aria-label="t('creatorProfile.closeGallery')"
            @click="closePortfolioGallery"
          >
            <X :size="21" aria-hidden="true" />
          </button>
        </header>
        <div class="portfolio-gallery-dialog__grid">
          <article v-for="(item, index) in visiblePortfolio" :key="item.id" class="profile-gallery__item">
            <div class="profile-gallery__media">
              <button
                v-if="item.type === 'image'"
                class="profile-gallery__image-button"
                type="button"
                :aria-label="item.title || creator.displayName"
                @click="openLightbox(index)"
              >
                <img :src="item.url" :alt="item.title || creator.displayName" loading="lazy" />
              </button>
              <iframe
                v-else
                :src="item.embedUrl"
                :title="item.title || creator.displayName"
                loading="lazy"
                allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
              ></iframe>
            </div>
            <p v-if="item.title">{{ item.title }}</p>
          </article>
        </div>
      </div>
    </dialog>
    <section class="profile-layout page-width">
      <div class="profile-main">
        <div class="profile-heading">
          <div class="profile-heading__identity">
            <div class="profile-heading__avatar">
              <img v-if="creator.avatarUrl" :src="creator.avatarUrl" :alt="creator.displayName" />
              <WaveLogo v-else mark />
            </div>
            <div>
              <p class="eyebrow">{{ categoryLabel }} · {{ creator.location }}</p>
              <h1>{{ creator.displayName }}</h1>
              <p class="profile-tagline">{{ creator.tagline }}</p>
            </div>
          </div>
          <button class="button button--outline" type="button" @click="shareProfile">
            {{ copied ? t('creatorProfile.copied') : `${t('creatorProfile.share')} ↗` }}
          </button>
        </div>
        <div class="profile-bio" v-html="sanitizeRichText(creator.bio)"></div>
        <div class="profile-tags profile-tags--categories">
          <span v-for="category in creator.categoryLabels" :key="category">{{ category }}</span>
        </div>
        <div class="profile-tags"><span v-for="tag in creator.tags" :key="tag">{{ tag }}</span></div>
        <StatusMessage v-if="catalogError" variant="error">{{ catalogError }}</StatusMessage>

        <div v-if="creator.socialProfiles.length" class="profile-stat-pills">
          <span v-for="profile in creator.socialProfiles" :key="profile.platform">
            <strong>{{ formatFollowers(profile.followers) }}</strong>
            {{ profile.platform }}
          </span>
        </div>

        <section class="profile-content-section profile-community">
          <div class="profile-section-heading">
            <div><p class="eyebrow">{{ t('creatorProfile.mediaKit') }}</p><h2>{{ t('creatorProfile.community') }}</h2></div>
            <span class="self-reported-note">{{ t('creatorProfile.selfReported') }}</span>
          </div>
          <div class="metric-grid">
            <article v-for="profile in creator.socialProfiles" :key="profile.platform" class="metric-card">
              <span class="metric-card__platform">{{ profile.platform }}</span>
              <strong>{{ formatFollowers(profile.followers) }}</strong>
              <span class="metric-card__handle">{{ profile.handle }}</span>
              <span class="metric-card__updated">{{ t('creatorProfile.updated', { date: formatDate(profile.lastUpdated) }) }}</span>
            </article>
            <StatusMessage v-if="!creator.socialProfiles.length" variant="empty">
              {{ t('creatorProfile.emptyChannels') }}
            </StatusMessage>
          </div>
          <p class="profile-disclaimer">{{ t('creatorProfile.disclaimer') }}</p>
        </section>

        <div class="profile-platform-pills" role="tablist" :aria-label="t('creatorProfile.platforms')">
          <button
            v-for="platform in availablePlatforms"
            :key="platform"
            class="profile-platform-pills__item"
            :class="{ 'is-active': selectedPlatform === platform }"
            type="button"
            role="tab"
            :aria-selected="selectedPlatform === platform"
            @click="selectPlatform(platform)"
          >
            {{ platform === 'All' ? t('creatorProfile.allPlatforms') : platform }}
          </button>
        </div>

        <section v-if="visiblePackages.length" class="profile-content-section profile-packages">
          <div class="profile-section-heading">
            <div><p class="eyebrow">{{ t('creatorProfile.collaborateEyebrow') }}</p><h2>{{ t('creatorProfile.packages') }}</h2></div>
            <span>{{ t('creatorProfile.packagesNote') }}</span>
          </div>
          <div class="profile-package-grid">
            <article v-for="packageItem in visiblePackages" :key="packageItem.id" class="profile-package">
              <div class="profile-package__details">
                <p class="eyebrow">{{ packageItem.platform }}</p>
                <h3>{{ packageItem.title }}</h3>
                <p :class="{ 'is-expanded': expandedPackageIds.has(packageItem.id) }">{{ packageItem.description }}</p>
                <button
                  v-if="packageItem.description.length > 90"
                  class="profile-package__toggle"
                  type="button"
                  :aria-expanded="expandedPackageIds.has(packageItem.id)"
                  @click="togglePackageDescription(packageItem.id)"
                >
                  {{ t(expandedPackageIds.has(packageItem.id) ? 'creatorProfile.showLess' : 'creatorProfile.showMore') }}
                </button>
              </div>
              <div class="profile-package__actions">
                <strong>{{ packageItem.price ? formatMoney(packageItem.price, packageItem.currency) : t('creatorProfile.priceOnRequest') }}</strong>
                <button class="button button--dark button--full" type="button" @click="openRequestForm(packageItem)">
                  {{ t('creatorProfile.requestPackage') }} <span aria-hidden="true">↗</span>
                </button>
              </div>
            </article>
          </div>
        </section>

        <section v-if="visiblePortfolio.length" class="profile-content-section profile-portfolio">
          <div
            class="profile-gallery profile-gallery--desktop"
            :class="{
              'profile-gallery--three': featuredPortfolio.length === 3,
              'profile-gallery--two': featuredPortfolio.length === 2,
              'profile-gallery--single': featuredPortfolio.length === 1,
            }"
            :aria-label="t('creatorProfile.portfolio')"
          >
            <article
              v-for="(item, index) in featuredPortfolio"
              :key="item.id"
              class="profile-gallery__item"
            >
              <div class="profile-gallery__media">
                <button
                  v-if="item.type === 'image'"
                  class="profile-gallery__image-button"
                  type="button"
                  :aria-label="item.title || creator.displayName"
                  @click="openLightbox(index)"
                >
                  <img :src="item.url" :alt="item.title || creator.displayName" loading="lazy" />
                </button>
                <iframe
                  v-else
                  :src="item.embedUrl"
                  :title="item.title || creator.displayName"
                  loading="lazy"
                  allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                  allowfullscreen
                  referrerpolicy="strict-origin-when-cross-origin"
                ></iframe>
              </div>
              <p v-if="item.title">{{ item.title }}</p>
            </article>
          </div>

          <div
            ref="mobileGallery"
            class="profile-gallery profile-gallery--mobile"
            :aria-label="t('creatorProfile.portfolio')"
            @scroll.passive="updateCurrentPortfolioIndex"
          >
            <article v-for="(item, index) in visiblePortfolio" :key="item.id" class="profile-gallery__item">
              <div class="profile-gallery__media">
                <button
                  v-if="item.type === 'image'"
                  class="profile-gallery__image-button"
                  type="button"
                  :aria-label="item.title || creator.displayName"
                  @click="openLightbox(index)"
                >
                  <img :src="item.url" :alt="item.title || creator.displayName" loading="lazy" />
                </button>
                <iframe
                  v-else
                  :src="item.embedUrl"
                  :title="item.title || creator.displayName"
                  loading="lazy"
                  allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                  allowfullscreen
                  referrerpolicy="strict-origin-when-cross-origin"
                ></iframe>
              </div>
              <p v-if="item.title">{{ item.title }}</p>
            </article>
          </div>
          <div v-if="visiblePortfolio.length > 1" class="profile-gallery__controls">
            <div class="profile-gallery__dots" role="group" :aria-label="t('creatorProfile.portfolio')">
              <button
                v-for="(item, index) in visiblePortfolio"
                :key="item.id"
                type="button"
                :class="{ 'is-active': currentPortfolioIndex === index }"
                :aria-label="t('creatorProfile.goToItem', { index: index + 1 })"
                :aria-current="currentPortfolioIndex === index ? 'true' : undefined"
                @click="scrollToPortfolioItem(index)"
              ></button>
            </div>
            <span class="profile-gallery__counter" aria-live="polite">
              {{ currentPortfolioIndex + 1 }} / {{ visiblePortfolio.length }}
            </span>
          </div>
          <div v-if="visiblePortfolio.length > 3" class="profile-gallery__footer">
            <button class="profile-gallery__show-all" type="button" @click="openPortfolioGallery">
              {{ t('creatorProfile.showAllPortfolio', { count: visiblePortfolio.length }) }}
            </button>
          </div>
        </section>

        <dialog
          ref="portfolioLightbox"
          class="portfolio-lightbox"
          :aria-label="t('creatorProfile.portfolio')"
          @click.self="closeLightbox"
          @keydown.left.prevent="moveLightbox(-1)"
          @keydown.right.prevent="moveLightbox(1)"
        >
          <div v-if="lightboxItem" class="portfolio-lightbox__content">
            <button
              class="portfolio-lightbox__close"
              type="button"
              :aria-label="t('creatorProfile.closeGallery')"
              autofocus
              @click="closeLightbox"
            >
              <X :size="21" aria-hidden="true" />
            </button>
            <button
              class="portfolio-lightbox__arrow portfolio-lightbox__arrow--previous"
              type="button"
              :aria-label="t('creatorProfile.previousItem')"
              :disabled="visiblePortfolio.length < 2"
              @click="moveLightbox(-1)"
            >
              <ChevronLeft :size="25" aria-hidden="true" />
            </button>
            <div class="portfolio-lightbox__media">
              <img
                v-if="lightboxItem.type === 'image'"
                :src="lightboxItem.url"
                :alt="lightboxItem.title || creator.displayName"
              />
              <iframe
                v-else
                :src="lightboxItem.embedUrl"
                :title="lightboxItem.title || creator.displayName"
                allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
              ></iframe>
            </div>
            <button
              class="portfolio-lightbox__arrow portfolio-lightbox__arrow--next"
              type="button"
              :aria-label="t('creatorProfile.nextItem')"
              :disabled="visiblePortfolio.length < 2"
              @click="moveLightbox(1)"
            >
              <ChevronRight :size="25" aria-hidden="true" />
            </button>
            <div class="portfolio-lightbox__caption">
              <span>{{ lightboxItem.title || creator.displayName }}</span>
              <span>{{ lightboxIndex + 1 }} / {{ visiblePortfolio.length }}</span>
            </div>
          </div>
        </dialog>

        <FaqSection
          v-if="profileFaqs.length"
          class="profile-content-section profile-faqs"
          :eyebrow="t('creatorProfile.faqEyebrow')"
          :title="t('creatorProfile.faqTitle')"
          :items="profileFaqs"
        />

        <section class="profile-content-section profile-request">
          <p class="eyebrow">{{ t('creatorProfile.requestEyebrow') }}</p>
          <h2>{{ t('creatorProfile.requestTitle') }}</h2>
          <p>{{ t('creatorProfile.requestDescription') }}</p>
          <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
          <StatusMessage v-if="notice">{{ notice }}</StatusMessage>
          <div v-if="viewer?.accountType !== 'company'" class="profile-request__login">
            <p>{{ t('creatorProfile.companyOnly') }}</p>
            <RouterLink class="button button--dark" to="/account">{{ t('creatorProfile.signInAsCompany') }} <span aria-hidden="true">↗</span></RouterLink>
          </div>
          <StatusMessage v-else-if="!viewer.emailVerified" variant="error">
            {{ t('creatorProfile.verifyEmail') }}
          </StatusMessage>
          <form v-else class="profile-request__form" @submit.prevent="submitRequest">
            <label class="form-field">
              <span>{{ t('creatorProfile.choosePackage') }}</span>
              <select v-model="requestForm.packageId" @change="syncRequestCurrency">
                <option value="">{{ t('creatorProfile.generalRequest') }}</option>
                <option v-for="packageItem in creator.packages" :key="packageItem.id" :value="packageItem.id">
                  {{ packageItem.title }} · {{ packageItem.price ? formatMoney(packageItem.price, packageItem.currency) : t('creatorProfile.priceOnRequest') }}
                </option>
              </select>
            </label>
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
              {{ t('creatorProfile.sendRequest') }} <span aria-hidden="true">↗</span>
            </button>
          </form>
        </section>
      </div>
      <aside class="profile-aside">
        <p class="eyebrow">{{ t('creatorProfile.goodFit') }}</p>
        <h3>{{ t('creatorProfile.brandsCategory', { category: categoryLabel.toLowerCase() }) }}</h3>
        <p>{{ t('creatorProfile.goodFitDescription') }}</p>
        <button class="button button--dark button--full" type="button" @click="openRequestForm()">
          {{ t('creatorProfile.requestCollaboration') }} <span aria-hidden="true">↗</span>
        </button>
        <p class="profile-aside__note">{{ t('creatorProfile.chatNote') }}</p>
        <form
          v-if="viewer?.accountType === 'company' && viewer.emailVerified && viewer.approved"
          class="campaign-invite-form"
          @submit.prevent="sendCampaignInvitation"
        >
          <h4>{{ t('campaignChat.inviteTitle') }}</h4>
          <p>{{ t('campaignChat.inviteDescription') }}</p>
          <StatusMessage v-if="inviteError" variant="error">{{ inviteError }}</StatusMessage>
          <template v-if="inviteCampaigns.length">
            <label class="form-field">
              <span>{{ t('campaignChat.chooseCampaign') }}</span>
              <select v-model="inviteForm.campaignSlug" required>
                <option v-for="campaign in inviteCampaigns" :key="campaign.id" :value="campaign.slug">
                  {{ campaign.title }}
                </option>
              </select>
            </label>
            <label class="form-field">
              <span>{{ t('campaignChat.invitationMessage') }}</span>
              <textarea v-model.trim="inviteForm.message" required minlength="1" maxlength="2000"></textarea>
            </label>
            <button class="button button--outline button--full" type="submit" :disabled="inviteBusy">
              {{ inviteBusy ? t('campaignChat.sending') : t('campaignChat.inviteButton') }}
            </button>
          </template>
          <StatusMessage v-else variant="empty">{{ t('campaignChat.noInviteCampaigns') }}</StatusMessage>
        </form>
      </aside>
    </section>
  </template>
</template>
