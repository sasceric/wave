<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import CountryDirectoryLinks from '../components/shared/CountryDirectoryLinks.vue'
import SeoGuideLinks from '../components/shared/SeoGuideLinks.vue'
import SeoHeroArtwork from '../components/shared/SeoHeroArtwork.vue'
import NotFoundView from './NotFoundView.vue'
import { guideSlugs, creatorGuideSlugs } from '../lib/regionalSeo'
import { apiGet } from '../lib/api'
import { ArrowLeft, ArrowRight, BadgeCheck, BriefcaseBusiness, FileText, Images, MapPin, PlayCircle, Search, Send, UserRound } from '@lucide/vue'
import creatorRegionMap from '../assets/creator-region-map.svg'

const { t } = useI18n()
const route = useRoute()
const guide = computed(() => route.params.guide)
const forCreators = computed(() => ['creator-guides', 'creator-guide'].includes(route.meta.routeName))
const slugs = computed(() => forCreators.value ? creatorGuideSlugs : guideSlugs)
const copyKey = computed(() => forCreators.value ? 'regionalSeo.creatorGuides.guides' : 'regionalSeo.guides')
const titleKey = computed(() => forCreators.value ? 'regionalSeo.creatorGuides.title' : 'regionalSeo.guideTitle')
const valid = computed(() => !guide.value || slugs.value.includes(guide.value))
const guideImages = {
  'find-creators': '/images/home-step-match.webp',
  'choose-package': '/images/home-step-create.webp',
  'campaign-brief': '/images/home-step-brief.webp',
  'create-profile': '/images/home-step-create.webp',
  'offer-packages': '/images/home-step-brief.webp',
  'apply-to-campaigns': '/images/home-step-match.webp',
}
const guideIcons = { 'find-creators': UserRound, 'choose-package': PlayCircle, 'campaign-brief': FileText, 'create-profile': UserRound, 'offer-packages': FileText, 'apply-to-campaigns': PlayCircle }
const stepIcons = {
  'find-creators': [Search, UserRound, Send],
  'choose-package': [BriefcaseBusiness, Images, BadgeCheck],
  'campaign-brief': [FileText, BriefcaseBusiness, BadgeCheck],
  'create-profile': [UserRound, Images, BadgeCheck],
  'offer-packages': [BriefcaseBusiness, FileText, Send],
  'apply-to-campaigns': [Search, Send, BadgeCheck],
}
const regionalCreators = ref([])
onMounted(async () => {
  if (guide.value) return
  try {
    const response = await apiGet('/creators?limit=10&sort=newest&view=card')
    regionalCreators.value = response.data || []
  } catch {
    regionalCreators.value = []
  }
})
</script>

<template>
  <section v-if="valid" class="page-width seo-content" :class="{ 'seo-content--article': guide }">
    <LocalizedLink v-if="guide" class="text-link seo-content__back" :to="{ name: forCreators ? 'creator-guides' : 'seo-guides' }"><ArrowLeft :size="17" aria-hidden="true" /> {{ t('regionalSeo.back') }}</LocalizedLink>
    <div v-if="!guide" class="seo-content__hero">
      <div>
        <p class="eyebrow">{{ t(forCreators ? 'regionalSeo.creatorGuides.eyebrow' : 'regionalSeo.eyebrow') }}</p>
        <h1>{{ t(titleKey) }}</h1>
        <p class="seo-content__intro">{{ t(forCreators ? 'regionalSeo.creatorGuides.intro' : 'regionalSeo.guideIntro') }}</p>
      </div>
      <SeoHeroArtwork />
    </div>
    <div v-else class="seo-content__hero seo-content__hero--article">
      <div>
        <p class="eyebrow">{{ t(forCreators ? 'regionalSeo.creatorGuides.eyebrow' : 'regionalSeo.eyebrow') }}</p>
        <h1>{{ t(`${copyKey}.${guide}.title`) }}</h1>
        <p class="seo-content__intro">{{ t(`${copyKey}.${guide}.intro`) }}</p>
      </div>
      <SeoHeroArtwork article :icon="guideIcons[guide]" />
    </div>
    <article v-if="guide" class="seo-content__article">
      <section v-for="index in 3" :key="`${guide}-${index}`" class="seo-content__step">
        <span class="seo-content__step-number" aria-hidden="true">{{ index }}</span>
        <span class="seo-content__step-icon"><component :is="stepIcons[guide][index - 1]" :size="28" aria-hidden="true" /></span>
        <div>
          <h2>{{ t(`${copyKey}.${guide}.sections.${index - 1}.heading`) }}</h2>
          <p>{{ t(`${copyKey}.${guide}.sections.${index - 1}.body`) }}</p>
        </div>
      </section>
    </article>
    <div v-else class="seo-content__guides">
      <LocalizedLink
        v-for="slug in slugs"
        :key="slug"
        class="seo-content__guide-card"
        :to="{ name: forCreators ? 'creator-guide' : 'seo-guide', params: { guide: slug } }"
        :aria-labelledby="`guide-title-${slug}`"
      >
        <div class="seo-content__guide-media">
          <img :src="guideImages[slug]" alt="" loading="lazy">
        </div>
        <div class="seo-content__guide-body">
          <h2 :id="`guide-title-${slug}`">{{ t(`${copyKey}.${slug}.title`) }} <ArrowRight :size="17" aria-hidden="true" /></h2>
          <p>{{ t(`${copyKey}.${slug}.intro`) }}</p>
          <div class="seo-content__guide-meta"><component :is="guideIcons[slug]" :size="20" aria-hidden="true" /><span>{{ t(`${copyKey}.${slug}.sections.0.heading`) }}</span></div>
        </div>
      </LocalizedLink>
    </div>
    <div class="seo-content__actions">
      <LocalizedLink class="button button--dark" :to="{ name: forCreators ? 'campaigns' : 'creators' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.browse' : 'regionalSeo.browse') }} <ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
      <LocalizedLink class="button button--outline" :to="{ name: forCreators ? 'account' : 'account-campaign-create' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.profile' : 'regionalSeo.campaign') }}</LocalizedLink>
      <LocalizedLink class="text-link" :to="{ name: 'how-it-works' }">{{ t('regionalSeo.howItWorks.title') }} <ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
    </div>

    <section v-if="!guide" class="seo-content__regional">
      <div class="seo-content__regional-copy">
        <h2>{{ t('regionalSeo.countriesTitle') }}</h2>
        <p>{{ t('regionalSeo.regionalIntro') }}</p>
        <div class="seo-content__avatars" aria-hidden="true">
          <span v-for="creator in regionalCreators" :key="creator.slug"><img :src="creator.avatarUrl || '/images/creator-placeholder.webp'" alt="" loading="lazy"></span>
          <span v-if="regionalCreators.length" class="seo-content__avatar-more">+</span>
        </div>
        <CountryDirectoryLinks compact />
      </div>
      <div class="seo-content__regional-side">
        <div class="seo-content__regional-aside">
          <MapPin :size="26" aria-hidden="true" />
          <h3>{{ t('regionalSeo.regionalAsideTitle') }}</h3>
          <p>{{ t('regionalSeo.regionalAsideBody') }}</p>
        </div>
        <div class="seo-content__regional-map" aria-hidden="true">
          <img class="seo-content__map-base" :src="creatorRegionMap" alt="">
        </div>
      </div>
    </section>
    <div v-else class="seo-content__related">
      <SeoGuideLinks panel :for-creators="forCreators" />
      <section class="seo-content__region-card">
        <div>
          <h2>{{ t('regionalSeo.countriesTitle') }}</h2>
          <p>{{ t('regionalSeo.regionalIntro') }}</p>
          <CountryDirectoryLinks compact />
        </div>
        <img :src="creatorRegionMap" alt="" loading="lazy" aria-hidden="true">
      </section>
    </div>
  </section>
  <NotFoundView v-else />
</template>

<style lang="scss" src="../scss/components/shared/SeoContent.scss"></style>
