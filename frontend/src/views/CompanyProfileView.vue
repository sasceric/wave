<script setup>
import AgencyPublicProfile from '../components/companies/AgencyPublicProfile.vue'
import PublicShareButton from '../components/shared/PublicShareButton.vue'
import PresentationDemoBadge from '../components/shared/PresentationDemoBadge.vue'
import MarketplaceSafetyActions from '../components/shared/MarketplaceSafetyActions.vue'
import ProfileReviews from '../components/reviews/ProfileReviews.vue'
import RatingSummary from '../components/reviews/RatingSummary.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ArrowLeft, ArrowRight, Briefcase, ChevronRight, MapPin, Megaphone, UsersRound } from '@lucide/vue'
import SocialPlatformIcon from '../components/shared/SocialPlatformIcon.vue'
import CardGrid from '../components/shared/CardGrid.vue'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import ProfileStats from '../components/shared/ProfileStats.vue'
import ProfileActionCard from '../components/shared/ProfileActionCard.vue'
import { useRoute } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import { PROFILE_CAMPAIGN_IMAGE_SIZES } from '../lib/listingImage'
import { apiGet } from '../lib/api'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'
import { sanitizeRichText } from '../lib/richText'
import { formatAverageRating } from '../lib/profileStats'

const route = useRoute()
const company = ref(null)
const campaigns = ref([])
const error = ref('')
const { t, locale } = useI18n()
let profileGeneration = 0
const countryDisplayLocale = computed(() => {
  if (locale.value === 'cnr') return 'bs'
  if (locale.value === 'sr') return 'sr-Latn'

  return locale.value
})
const locationLabel = computed(() => {
  if (!company.value) return ''
  const countryName = company.value.countryCode
    ? new Intl.DisplayNames([countryDisplayLocale.value], { type: 'region' }).of(company.value.countryCode)
    : ''

  return [company.value.city, countryName].filter(Boolean).join(', ')
})
const aboutHtml = computed(() => sanitizeRichText(company.value?.about || ''))
const industries = computed(() => company.value?.industryLabels?.length
  ? company.value.industryLabels
  : (company.value?.industries || [company.value?.industry]).filter(Boolean))
const coverUrl = computed(() => company.value?.coverUrl || '/images/company-cover.webp')
const socialLinks = computed(() => (company.value?.socialLinks || []).filter((link) => link.platform && link.url))
const activeSection = ref('company-campaigns')
const tabs = computed(() => [
  { key: 'company-campaigns', label: t('app.campaigns'), ...(campaigns.value.length ? { count: campaigns.value.length } : {}) },
  { key: 'company-about', label: t('companyProfile.aboutEyebrow') },
  { key: 'company-reviews', label: t('studio.tabReviews'), ...(company.value?.rating?.reviewCount ? { count: company.value.rating.reviewCount } : {}) },
])
const stats = computed(() => {
  const rating = company.value?.rating || {}
  return [
    { key: 'campaigns', label: t('publicProfile.openCampaigns'), value: String(campaigns.value.length), hint: t('publicProfile.openNow') },
    { key: 'collaborations', label: t('publicProfile.collaborations'), value: String(rating.completedCampaigns || 0), hint: t('publicProfile.collaborationsHint') },
    { key: 'rating', label: t('publicProfile.rating'), value: formatAverageRating(rating.average, locale.value), hint: t('studio.reviewCount', { count: rating.reviewCount || 0 }) },
  ]
})

function scrollToSection(id) {
  activeSection.value = id
  const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
  document.getElementById(id)?.scrollIntoView({ behavior, block: 'start' })
}

async function loadCompany() {
  const generation = ++profileGeneration
  company.value = null
  campaigns.value = []
  error.value = ''
  try {
    const slug = encodeURIComponent(route.params.slug)
    const [companyResponse, campaignResponse] = await Promise.all([
      apiGet(`/companies/${slug}`),
      apiGet(`/campaigns?company=${slug}&limit=50`),
    ])
    if (generation !== profileGeneration) return
    company.value = companyResponse.data
    campaigns.value = campaignResponse.data
  } catch (cause) {
    if (generation !== profileGeneration) return
    error.value = cause.message
    if (cause.status === 404 && !company.value) {
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

watch([() => route.params.slug, locale], loadCompany)
watch(
  [company, locale],
  ([value]) => {
    if (!value) return
    const logo = value.logoUrl ? new URL(value.logoUrl, getSeoOrigin()).href : new URL('/images/company-cover.webp', getSeoOrigin()).href
    updateSeo({
      route,
      locale: locale.value,
      title: t('seo.companyProfileTitle', { name: value.name }),
      description: value.structuredData?.description || value.industry,
      image: logo,
      noindex: value.indexable === false,
      mainEntity: value.structuredData || {
        '@type': 'Organization',
        name: value.name,
        url: new URL(localizedPath('company-profile', locale.value, { slug: value.slug }), getSeoOrigin()).href,
        knowsAbout: value.industry,
        logo,
      },
    })
  },
)
onMounted(loadCompany)
onBeforeUnmount(() => { ++profileGeneration })
</script>

<template>
  <section v-if="error" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/companies"><ArrowLeft class="link-arrow" :size="16" aria-hidden="true" /> {{ t('companyProfile.back') }}</RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!company" variant="company" :label="t('companyProfile.loading')" />
  <AgencyPublicProfile v-else-if="company.companyType === 'agency'" :company="company" :campaigns="campaigns" />
  <main v-else class="page-width company-profile">
    <nav class="company-profile__breadcrumb" :aria-label="t('creatorProfile.breadcrumbs')">
      <RouterLink :to="{ name: 'companies' }">{{ t('app.companies') }}</RouterLink>
      <ChevronRight :size="14" aria-hidden="true" />
      <span aria-current="page">{{ company.name }}</span>
      <PresentationDemoBadge v-if="company.isPresentationDemo" class="company-profile__demo" pill />
      <PublicShareButton class="company-profile__share" route-name="company-profile" :slug="company.slug" :title="company.name" />
    </nav>
    <section class="company-profile__hero">
      <div class="company-profile__hero-image" :style="{ backgroundImage: `url(${coverUrl})` }" aria-hidden="true" />
      <div class="company-profile__hero-card">
        <div class="company-profile__identity">
          <div class="company-profile__logo">
            <img v-if="company.logoUrl" :src="company.logoUrl" :alt="company.name" />
            <WaveLogo v-else mark />
          </div>
          <div class="company-profile__identity-copy">
            <p class="eyebrow">{{ t(`companyTypes.options.${company.companyType || 'brand'}`) }}<template v-if="industries.length"> · {{ industries.join(' · ') }}</template></p>
            <h1>{{ company.name }}<span v-if="company.verified" class="verified-mark" :aria-label="t('campaignCard.verified')">✓</span></h1>
          </div>
        </div>
        <p class="company-profile__tagline">{{ t(company.about ? 'companyProfile.profileIntro' : 'companyProfile.profileLabel') }}</p>
        <div class="company-profile__meta">
          <span v-if="locationLabel"><MapPin :size="15" aria-hidden="true" />{{ locationLabel }}</span>
          <RatingSummary :rating="company.rating" profile />
        </div>
        <div class="company-profile__hero-actions">
          <button class="button button--dark" type="button" @click="scrollToSection('company-campaigns')">
            <Megaphone :size="16" aria-hidden="true" />
            {{ t('publicProfile.openCampaignsCount', { count: campaigns.length }) }}
          </button>
          <RouterLink class="button button--glass" to="/campaigns">
            {{ t('companyProfile.allBriefs') }}
            <ArrowRight :size="16" aria-hidden="true" />
          </RouterLink>
        </div>
      </div>
    </section>
    <ProfileStats :items="stats" :label="t('publicProfile.statsLabel')" />
    <div class="profile-section-bar">
      <ProfileTabs v-model="activeSection" :items="tabs" :label="t('studio.profileSections')" sections />
    </div>

    <div class="company-profile__layout">
      <div class="company-profile__main">
        <section id="company-campaigns" class="company-profile__campaigns">
          <header class="company-profile__section-heading">
            <div>
              <h2>{{ t('publicProfile.openCampaigns') }}</h2>
              <p>{{ t('publicProfile.openCampaignsHint') }}</p>
            </div>
            <RouterLink class="text-link" to="/campaigns">{{ t('companyProfile.allBriefs') }} <ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></RouterLink>
          </header>
          <CardGrid v-if="campaigns.length" class="company-profile__campaign-grid" kind="campaign" layout="directory">
            <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="PROFILE_CAMPAIGN_IMAGE_SIZES" />
          </CardGrid>
          <StatusMessage v-else variant="empty">{{ t('companyProfile.empty') }}</StatusMessage>
        </section>
        <section id="company-about" class="company-profile__about">
          <h2>{{ t('companyProfile.aboutTitle', { company: company.name }) }}</h2>
          <div class="company-profile__about-card">
            <div v-if="aboutHtml" class="company-profile__rich-text" v-html="aboutHtml"></div>
            <p v-else class="company-profile__muted">{{ t('companyProfile.profileLabel') }}</p>
            <div v-if="industries.length" class="company-profile__tags">
              <span v-for="industry in industries" :key="industry">{{ industry }}</span>
            </div>
          </div>
        </section>
        <section id="company-reviews">
          <ProfileReviews kind="companies" :slug="company.slug" :title="t('publicProfile.brandReviewsTitle')" :description="t('publicProfile.brandReviewsHint')" />
        </section>
        <MarketplaceSafetyActions kind="company" :slug="company.slug" />
      </div>

      <aside class="company-profile__sidebar">
        <div class="company-profile__sidebar-sticky">
          <ProfileActionCard
            :label="t('publicProfile.actionsLabel')"
            :title="t('publicProfile.forCreators')"
            :description="t('publicProfile.forCreatorsHint')"
            :price-label="t('publicProfile.openCampaigns')"
            :price="campaigns.length ? String(campaigns.length) : ''"
            :price-in-card="false"
          >
            <button class="button button--dark" type="button" @click="scrollToSection('company-campaigns')">
              <Megaphone :size="16" aria-hidden="true" />
              {{ t('publicProfile.openCampaignsCount', { count: campaigns.length }) }}
            </button>
            <template #bar>
              <button class="button button--dark" type="button" @click="scrollToSection('company-campaigns')">{{ t('publicProfile.view') }}</button>
            </template>
          </ProfileActionCard>
          <article class="company-profile__details">
            <div v-if="locationLabel" class="company-profile__detail"><MapPin :size="16" aria-hidden="true" /><span>{{ t('auth.city') }}</span><strong>{{ locationLabel }}</strong></div>
            <div v-if="industries.length" class="company-profile__detail"><Briefcase :size="16" aria-hidden="true" /><span>{{ t('companyProfile.areas') }}</span><strong>{{ industries.join(', ') }}</strong></div>
            <div class="company-profile__detail"><UsersRound :size="16" aria-hidden="true" /><span>{{ t('publicProfile.collaborations') }}</span><strong>{{ company.rating?.completedCampaigns || 0 }}</strong></div>
            <div v-if="socialLinks.length" class="company-profile__social" :aria-label="t('companyProfile.followUs')">
              <a v-for="link in socialLinks" :key="link.platform" :href="link.url" :aria-label="`${company.name} · ${link.platform}`" :title="link.platform" target="_blank" rel="noopener noreferrer">
                <SocialPlatformIcon :platform="link.platform" :size="18" />
              </a>
            </div>
          </article>
        </div>
      </aside>
    </div>
  </main>
</template>

<style lang="scss" src="../scss/views/CompanyProfileView.scss"></style>
