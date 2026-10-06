<script setup>
import CardGrid from '../components/shared/CardGrid.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import { DIRECTORY_IMAGE_SIZES } from '../lib/listingImage'
import { apiGet } from '../lib/api'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'
import { sanitizeRichText } from '../lib/richText'

const route = useRoute()
const company = ref(null)
const campaigns = ref([])
const error = ref('')
const { t, locale } = useI18n()
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

async function loadCompany() {
  company.value = null
  campaigns.value = []
  error.value = ''
  try {
    const slug = encodeURIComponent(route.params.slug)
    const [companyResponse, campaignResponse] = await Promise.all([
      apiGet(`/companies/${slug}`),
      apiGet(`/campaigns?company=${slug}&limit=50`),
    ])
    company.value = companyResponse.data
    campaigns.value = campaignResponse.data
  } catch (cause) {
    error.value = cause.message
  }
}

watch([() => route.params.slug, locale], loadCompany)
watch(
  [company, locale],
  ([value]) => {
    if (!value) return

    const logo = value.logoUrl ? new URL(value.logoUrl, getSeoOrigin()).href : undefined
    updateSeo({
      route,
      locale: locale.value,
      title: t('seo.companyProfileTitle', { name: value.name }),
      description: value.industry,
      image: logo,
      mainEntity: {
        '@type': 'Organization',
        name: value.name,
        url: new URL(
          localizedPath('company-profile', locale.value, { slug: value.slug }),
          getSeoOrigin(),
        ).href,
        industry: value.industry,
        ...(logo ? { logo } : {}),
      },
    })
  },
)
onMounted(loadCompany)
</script>

<template>
  <section v-if="error" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/campaigns">
      {{ t('companyProfile.back') }} ↗
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!company" variant="company" :label="t('companyProfile.loading')" />
  <template v-else>
    <section class="company-cover">
      <div class="page-width company-cover__inner">
        <div class="company-logo">
          <img v-if="company.logoUrl" :src="company.logoUrl" :alt="company.name" />
          <WaveLogo v-else mark />
        </div>
        <p class="eyebrow">{{ company.industry }}</p>
        <h1>{{ company.name }}<span v-if="company.verified" class="verified-mark" :aria-label="t('campaignCard.verified')">✓</span></h1>
        <p v-if="locationLabel" class="company-cover__location">{{ locationLabel }}</p>
        <p>{{ t('companyProfile.profileLabel') }}</p>
        <div v-if="aboutHtml" class="company-cover__about" v-html="aboutHtml"></div>
      </div>
    </section>
    <section class="page-width company-briefs">
      <div class="section-heading">
        <div><p class="eyebrow">{{ t('companyProfile.openCalls') }}</p><h2>{{ t('companyProfile.campaignsFrom', { company: company.name }) }}</h2></div>
        <RouterLink class="text-link" to="/campaigns">{{ t('companyProfile.allBriefs') }} <span aria-hidden="true">↗</span></RouterLink>
      </div>
      <CardGrid v-if="campaigns.length" kind="campaign" layout="directory">
        <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="DIRECTORY_IMAGE_SIZES" />
      </CardGrid>
      <StatusMessage v-else variant="empty">
        {{ t('companyProfile.empty') }}
      </StatusMessage>
    </section>
  </template>
</template>

<style lang="scss" src="../scss/views/CompanyProfileView.scss"></style>
