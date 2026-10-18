<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CalendarCheck2, CirclePlay, ExternalLink, Languages, MapPin } from '@lucide/vue'
import AgencyContactActions from './AgencyContactActions.vue'
import AgencyProfileSections from './AgencyProfileSections.vue'
import ProfileTabs from '../shared/ProfileTabs.vue'
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import SocialPlatformIcon from '../shared/SocialPlatformIcon.vue'
import RichTextContent from '../shared/RichTextContent.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import MarketplaceSafetyActions from '../shared/MarketplaceSafetyActions.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import WaveLogo from '../shared/WaveLogo.vue'
import RatingSummary from '../reviews/RatingSummary.vue'
import ProfileReviews from '../reviews/ProfileReviews.vue'
import CampaignCard from '../campaigns/CampaignCard.vue'
import { formatDate } from '../../lib/api'
import { DIRECTORY_IMAGE_SIZES } from '../../lib/listingImage'
import { sanitizeRichText } from '../../lib/richText'
const props = defineProps({ company: { type: Object, required: true }, campaigns: { type: Array, default: () => [] } })
const { t, locale } = useI18n()
const contact = ref(null)
const activeSection = ref('company-services')
const reviewRole = ref('provider')
const industries = computed(() => props.company.industryLabels?.length ? props.company.industryLabels : props.company.industries || [])
const location = computed(() => {
  const code = props.company.countryCode
  const displayLocale = locale.value === 'cnr' ? 'bs' : locale.value === 'sr' ? 'sr-Latn' : locale.value
  return [props.company.city, code ? new Intl.DisplayNames([displayLocale], { type: 'region' }).of(code) : ''].filter(Boolean).join(', ')
})
const intro = computed(() => {
  const element = document.createElement('div')
  element.innerHTML = sanitizeRichText(props.company.about || '')
  const text = (element.querySelector('p')?.textContent || element.textContent || '').trim()
  return text.length > 210 ? `${text.slice(0, 207).trimEnd()}…` : text
})
const social = computed(() => (props.company.socialLinks || []).filter(link => link.platform && link.url))
const availability = computed(() => props.company.presentation?.availability && props.company.presentation.availability !== 'unspecified' ? t(`agency.${props.company.presentation.availability}`) : '')
const tabs = computed(() => [
  { key: 'company-services', label: t('agency.services') },
  ...(props.company.portfolio?.length ? [{ key: 'company-works', label: t('studio.tabWorks') }] : []),
  { key: 'company-packages', label: t('studio.tabPackages') },
  { key: 'company-reviews', label: t('studio.tabReviews') },
  ...(props.company.faqs?.length ? [{ key: 'company-faqs', label: t('studio.tabFaq') }] : []),
  { key: 'company-campaigns', label: t('app.campaigns') },
])
const reviewTabs = computed(() => ['provider', 'client'].map(key => ({ key, label: t(key === 'provider' ? 'agency.asProvider' : 'agency.asClient') })))
</script>
<template>
  <main class="page-width agency-public-profile">
    <div class="agency-public-profile__breadcrumb"><LocalizedLink :to="{ name: 'companies' }">{{ t('app.companies') }}</LocalizedLink><span aria-hidden="true">›</span><span>{{ company.name }}</span><PresentationDemoBadge v-if="company.isPresentationDemo" /></div>
    <div class="agency-public-profile__cover"><img :src="company.coverUrl || '/images/company-cover.webp'" :alt="t('account.companyCover')" /></div>
    <header class="agency-public-profile__hero">
      <div class="agency-public-profile__logo"><img v-if="company.logoUrl" :src="company.logoUrl" :alt="company.name" /><WaveLogo v-else mark /></div>
      <div class="agency-public-profile__identity"><p class="eyebrow">{{ t('companyTypes.options.agency') }}<template v-if="industries.length"> · {{ industries.join(' · ') }}</template></p><h1>{{ company.name }}<span v-if="company.verified" class="verified-mark" :aria-label="t('campaignCard.verified')">✓</span></h1><p v-if="intro" class="agency-public-profile__intro">{{ intro }}</p><div class="agency-public-profile__inline"><span v-if="location"><MapPin :size="15" aria-hidden="true" />{{ location }}</span><a href="#company-reviews" @click="activeSection = 'company-reviews'"><RatingSummary :rating="company.rating" profile /><span class="sr-only">{{ t('agency.combinedRating') }}</span></a></div></div>
      <div class="agency-public-profile__actions"><AgencyContactActions ref="contact" :company="company" /><span v-if="availability" class="agency-public-profile__availability" :class="{ 'is-available': company.presentation.availability === 'available' }">{{ availability }}<template v-if="company.presentation.availability === 'from' && company.presentation.availableFrom"> · {{ formatDate(company.presentation.availableFrom) }}</template></span></div>
    </header>
    <ProfileTabs v-model="activeSection" :items="tabs" :label="t('studio.profileSections')" sections />
    <AgencyProfileSections :company="company" @request="contact?.openRequest($event)">
      <template #reviews>
        <ProfileReviews id="company-reviews" kind="companies" :slug="company.slug" :review-role="reviewRole" :title="t('agency.publicReviewsTitle')" :description="t('agency.publicReviewsHint')"><template #filters><ProfileTabs v-model="reviewRole" :items="reviewTabs" :label="t('reviews.title')" id-prefix="agency-review" /></template></ProfileReviews>
      </template>
      <template #campaigns>
        <section id="company-campaigns" class="agency-public-profile__campaigns"><header class="agency-public-profile__section-heading"><h2>{{ t('agency.campaignsTitle', { company: company.name }) }}</h2><LocalizedLink class="text-link" :to="{ name: 'campaigns' }">{{ t('companyProfile.allBriefs') }}<ExternalLink :size="14" aria-hidden="true" /></LocalizedLink></header><div v-if="campaigns.length" class="agency-public-profile__campaign-grid"><CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="DIRECTORY_IMAGE_SIZES" /></div><StatusMessage v-else variant="empty">{{ t('agency.emptyCampaigns') }}</StatusMessage></section>
      </template>
      <template #about>
        <section id="company-about" class="agency-public-profile__about"><h2>{{ t('agency.about') }}</h2><RichTextContent v-if="company.about" :html="company.about" /><div class="agency-public-profile__tags"><span v-for="industry in industries" :key="industry">{{ industry }}</span></div><dl class="agency-public-profile__facts"><div v-if="location"><MapPin :size="16" aria-hidden="true" /><div><dt>{{ t('auth.city') }}</dt><dd>{{ location }}</dd></div></div><div v-if="company.presentation?.languages?.length"><Languages :size="16" aria-hidden="true" /><div><dt>{{ t('studio.languages') }}</dt><dd>{{ company.presentation.languages.map(value => t(`studio.contentLanguages.${value}`)).join(', ') }}</dd></div></div><div v-if="availability"><CalendarCheck2 :size="16" aria-hidden="true" /><div><dt>{{ t('studio.availability') }}</dt><dd>{{ availability }}<template v-if="company.presentation.availability === 'from' && company.presentation.availableFrom"> · {{ formatDate(company.presentation.availableFrom) }}</template></dd></div></div></dl><div v-if="social.length" class="agency-public-profile__social" :aria-label="t('companyProfile.followUs')"><a v-for="link in social" :key="link.platform" :href="link.url" :aria-label="`${company.name} · ${link.platform}`" target="_blank" rel="noopener noreferrer"><SocialPlatformIcon :platform="link.platform" :size="17" /></a></div><a v-if="company.presentation?.introVideoUrl" class="text-link agency-public-profile__video" :href="company.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer"><CirclePlay :size="17" aria-hidden="true" />{{ t('studio.introVideo') }}</a></section>
      </template>
    </AgencyProfileSections>
    <MarketplaceSafetyActions kind="company" :slug="company.slug" />
  </main>
</template>
<style lang="scss" src="../../scss/components/companies/AgencyPublicProfile.scss"></style>
