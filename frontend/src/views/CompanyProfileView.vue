<script setup>
import AgencyPublicProfile from '../components/companies/AgencyPublicProfile.vue'
import PresentationDemoBadge from '../components/shared/PresentationDemoBadge.vue'
import MarketplaceSafetyActions from '../components/shared/MarketplaceSafetyActions.vue'
import ProfileReviews from '../components/reviews/ProfileReviews.vue'
import RatingSummary from '../components/reviews/RatingSummary.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ExternalLink, Globe, MapPin, Megaphone } from '@lucide/vue'
import SocialPlatformIcon from '../components/shared/SocialPlatformIcon.vue'
import CardGrid from '../components/shared/CardGrid.vue'
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
    <RouterLink class="text-link" to="/companies">{{ t('companyProfile.back') }} ↗</RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!company" variant="company" :label="t('companyProfile.loading')" />
  <AgencyPublicProfile v-else-if="company.companyType === 'agency'" :company="company" :campaigns="campaigns" />
  <template v-else>
    <section class="company-profile__hero">
      <div class="company-profile__hero-image" :style="{ backgroundImage: `url(${coverUrl})` }" aria-hidden="true" />
      <div class="page-width company-profile__hero-inner">
        <div class="company-profile__hero-content">
          <PresentationDemoBadge v-if="company.isPresentationDemo" class="company-profile__demo" />
          <div class="company-profile__identity">
            <div class="company-profile__logo">
              <img v-if="company.logoUrl" :src="company.logoUrl" :alt="company.name" />
              <WaveLogo v-else mark />
            </div>
            <div>
              <p class="eyebrow">{{ t(`companyTypes.options.${company.companyType || 'brand'}`) }} · {{ industries.join(' · ') }}</p>
              <h1>{{ company.name }}<span v-if="company.verified" class="verified-mark" :aria-label="t('campaignCard.verified')">✓</span></h1>
              <RatingSummary :rating="company.rating" />
              <p class="company-profile__tagline">{{ t(company.about ? 'companyProfile.profileIntro' : 'companyProfile.profileLabel') }}</p>
            </div>
          </div>
          <div class="company-profile__stats">
            <span v-if="locationLabel"><MapPin :size="17" aria-hidden="true" />{{ locationLabel }}</span>
            <span v-for="link in socialLinks" :key="link.platform"><SocialPlatformIcon :platform="link.platform" :size="17" />{{ link.platform }}</span>
          </div>
        </div>
        <RouterLink class="button button--light company-profile__hero-action" to="/campaigns">
          {{ t('companyProfile.openCalls') }} <ExternalLink :size="16" aria-hidden="true" />
        </RouterLink>
      </div>
    </section>

    <main class="page-width company-profile__body">
      <div class="company-profile__overview-grid">
        <div class="company-profile__main">
          <article id="company-about" class="company-profile__card company-profile__about-card">
            <p class="eyebrow">{{ t('companyProfile.aboutEyebrow') }}</p>
            <h2>{{ t('companyProfile.aboutTitle', { company: company.name }) }}</h2>
            <div v-if="aboutHtml" class="company-profile__rich-text" v-html="aboutHtml"></div>
            <p v-else class="company-profile__muted">{{ t('companyProfile.profileLabel') }}</p>
            <div v-if="industries.length" class="company-profile__tags">
              <span v-for="industry in industries" :key="industry">{{ industry }}</span>
            </div>
          </article>
          <section id="company-reviews">
            <ProfileReviews kind="companies" :slug="company.slug" />
          </section>
        </div>

        <aside class="company-profile__sidebar">
          <article class="company-profile__card company-profile__details-card">
            <div v-if="locationLabel" class="company-profile__detail"><MapPin :size="19" aria-hidden="true" /><span>{{ t('auth.city') }}</span><strong>{{ locationLabel }}</strong></div>
            <div v-if="industries.length" class="company-profile__detail"><Globe :size="19" aria-hidden="true" /><span>{{ t('companyProfile.areas') }}</span><strong>{{ industries.join(', ') }}</strong></div>
            <div class="company-profile__detail"><Megaphone :size="19" aria-hidden="true" /><span>{{ t('companyProfile.openCalls') }}</span><strong>{{ campaigns.length }}</strong></div>
          </article>

          <article v-if="socialLinks.length" class="company-profile__card company-profile__social-card">
            <h2>{{ t('companyProfile.followUs') }}</h2>
            <a v-for="link in socialLinks" :key="link.platform" :href="link.url" target="_blank" rel="noopener noreferrer">
              <SocialPlatformIcon :platform="link.platform" :size="20" />
              <span>{{ link.platform }}</span>
              <ExternalLink :size="15" aria-hidden="true" />
            </a>
          </article>
        </aside>
      </div>

      <MarketplaceSafetyActions kind="company" :slug="company.slug" />
      <section id="company-campaigns" class="company-profile__campaigns">
        <div class="section-heading">
          <div><p class="eyebrow">{{ t('companyProfile.openCalls') }}</p><h2>{{ t('companyProfile.campaignsFrom', { company: company.name }) }}</h2></div>
          <RouterLink class="text-link" to="/campaigns">{{ t('companyProfile.allBriefs') }} <span aria-hidden="true">↗</span></RouterLink>
        </div>
        <CardGrid v-if="campaigns.length" class="company-profile__campaign-grid" kind="campaign" layout="directory">
          <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="PROFILE_CAMPAIGN_IMAGE_SIZES" />
        </CardGrid>
        <StatusMessage v-else variant="empty">{{ t('companyProfile.empty') }}</StatusMessage>
      </section>
    </main>
  </template>
</template>

<style lang="scss" src="../scss/views/CompanyProfileView.scss"></style>
