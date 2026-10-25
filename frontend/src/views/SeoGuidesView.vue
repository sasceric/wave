<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import CountryDirectoryLinks from '../components/shared/CountryDirectoryLinks.vue'
import GuideVideo from '../components/shared/GuideVideo.vue'
import NotFoundView from './NotFoundView.vue'
import { guideSlugs, creatorGuideSlugs } from '../lib/regionalSeo'
import { apiGet } from '../lib/api'
import { ArrowRight, BadgeCheck, BookOpen, BriefcaseBusiness, ChevronRight, FileText, Images, MapPin, PlayCircle, Search, Send, UserRound } from '@lucide/vue'

const { t } = useI18n()
const route = useRoute()
const guide = computed(() => route.params.guide)
const forCreators = computed(() => ['creator-guides', 'creator-guide'].includes(route.meta.routeName))
const slugs = computed(() => forCreators.value ? creatorGuideSlugs : guideSlugs)
const copyKey = computed(() => forCreators.value ? 'regionalSeo.creatorGuides.guides' : 'regionalSeo.guides')
const titleKey = computed(() => forCreators.value ? 'regionalSeo.creatorGuides.title' : 'regionalSeo.guideTitle')
const valid = computed(() => !guide.value || slugs.value.includes(guide.value))
const tutorials = [
  { id: 'nresOESQ5xs', topic: 'creatorProfile', creator: true, guide: 'create-profile', poster: '/images/tutorials/creator-profile.png' },
  { id: 'nLD4UMrJZOI', topic: 'collaboration', creator: true, guide: 'apply-to-campaigns', poster: '/images/tutorials/collaboration.png' },
  { id: 'z_raLkGJJdk', topic: 'companyProfile', creator: false, poster: '/images/tutorials/company-profile.png' },
  { id: 'UQz_2XxLQ9g', topic: 'campaigns', creator: false, guide: 'campaign-brief', poster: '/images/tutorials/campaigns.png' },
]
const videos = computed(() => tutorials.filter(video => video.creator === forCreators.value && (!guide.value || video.guide === guide.value)))
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
const indexRoute = computed(() => forCreators.value ? 'creator-guides' : 'seo-guides')
const articleRoute = computed(() => forCreators.value ? 'creator-guide' : 'seo-guide')
const otherSlugs = computed(() => slugs.value.filter(slug => slug !== guide.value))
// Large decorative photos (1050px and up) so the hero stays sharp.
const heroImage = computed(() => forCreators.value
  ? { src: '/images/home-faq-creator-1050.webp', srcset: '/images/home-faq-creator-1050.webp 1050w, /images/home-faq-creator-1400.webp 1400w' }
  : { src: '/images/minimalist-wave-768.webp', srcset: '/images/minimalist-wave-768.webp 768w, /images/minimalist-wave.webp 1122w' })

// Article contents: the link of the step that reached the top stays highlighted.
const activeStep = ref(1)
let stepFrame = 0
function trackStep() {
  stepFrame = 0
  if (!guide.value) return
  let current = 1
  for (const index of [1, 2, 3]) {
    const section = document.getElementById(`guide-step-${index}`)
    if (section && section.getBoundingClientRect().top <= 160) current = index
  }
  activeStep.value = current
}
function queueStep() { if (!stepFrame) stepFrame = window.requestAnimationFrame(trackStep) }
function goToStep(index) {
  activeStep.value = index
  const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
  document.getElementById(`guide-step-${index}`)?.scrollIntoView({ behavior, block: 'start' })
}
watch(guide, () => { activeStep.value = 1 })
onMounted(() => window.addEventListener('scroll', queueStep, { passive: true }))
onBeforeUnmount(() => {
  window.removeEventListener('scroll', queueStep)
  if (stepFrame) window.cancelAnimationFrame(stepFrame)
})

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
  <main v-if="valid" class="page-width guides-page" :class="{ 'guides-page--article': guide }">
    <nav class="guides-page__breadcrumb" :aria-label="t('creatorProfile.breadcrumbs')">
      <LocalizedLink to="/">{{ t('app.homeTab') }}</LocalizedLink>
      <ChevronRight :size="14" aria-hidden="true" />
      <LocalizedLink v-if="guide" :to="{ name: indexRoute }">{{ t(titleKey) }}</LocalizedLink>
      <span v-else aria-current="page">{{ t(titleKey) }}</span>
      <template v-if="guide"><ChevronRight :size="14" aria-hidden="true" /><span aria-current="page">{{ t(`${copyKey}.${guide}.title`) }}</span></template>
    </nav>

    <template v-if="!guide">
      <section class="guides-hero">
        <div class="guides-hero__copy">
          <p class="eyebrow">{{ t(forCreators ? 'regionalSeo.creatorGuides.eyebrow' : 'regionalSeo.eyebrow') }}</p>
          <h1>{{ t(titleKey) }}</h1>
          <p class="guides-hero__intro">{{ t(forCreators ? 'regionalSeo.creatorGuides.intro' : 'regionalSeo.guideIntro') }}</p>
          <nav class="guides-switch" :aria-label="t('regionalSeo.guidesUi.audience')">
            <LocalizedLink :to="{ name: 'seo-guides' }" :aria-current="!forCreators ? 'page' : undefined">{{ t('regionalSeo.guidesUi.forBrands') }}</LocalizedLink>
            <LocalizedLink :to="{ name: 'creator-guides' }" :aria-current="forCreators ? 'page' : undefined">{{ t('regionalSeo.guidesUi.forCreators') }}</LocalizedLink>
          </nav>
        </div>
        <div class="guides-hero__art" aria-hidden="true">
          <img :src="heroImage.src" :srcset="heroImage.srcset" sizes="(max-width: 1050px) 100vw, 46vw" alt="" fetchpriority="high">
          <div class="guides-hero__card">
            <span class="guides-icon"><BookOpen :size="22" /></span>
            <span><strong>{{ t(forCreators ? 'regionalSeo.guidesUi.forCreators' : 'regionalSeo.guidesUi.forBrandsLong') }}</strong>{{ t('regionalSeo.guidesUi.counts', { guides: slugs.length, videos: videos.length }) }}</span>
          </div>
        </div>
      </section>

      <section v-if="videos.length" class="guides-section" aria-labelledby="guide-videos-title">
        <header class="guides-section__head"><h2 id="guide-videos-title">{{ t('regionalSeo.videoTutorials.title') }}</h2><p>{{ t('regionalSeo.videoTutorials.intro') }}</p></header>
        <div class="guides-videos" :class="{ 'guides-videos--single': videos.length === 1 }">
          <GuideVideo v-for="video in videos" :key="video.id" :video="video" />
        </div>
      </section>

      <section class="guides-section" aria-labelledby="guide-written-title">
        <header class="guides-section__head"><h2 id="guide-written-title">{{ t('regionalSeo.guidesUi.writtenTitle') }}</h2><p>{{ t('regionalSeo.guidesUi.writtenIntro') }}</p></header>
        <div class="guides-cards">
          <LocalizedLink
            v-for="slug in slugs"
            :key="slug"
            class="guides-card"
            :to="{ name: articleRoute, params: { guide: slug } }"
            :aria-labelledby="`guide-title-${slug}`"
          >
            <span class="guides-card__media"><img :src="guideImages[slug]" alt="" loading="lazy" width="500" height="375"></span>
            <span class="guides-card__body">
              <small><component :is="guideIcons[slug]" :size="15" aria-hidden="true" />{{ t('regionalSeo.guidesUi.steps', { count: 3 }) }} · {{ t(`${copyKey}.${slug}.sections.0.heading`) }}</small>
              <strong :id="`guide-title-${slug}`">{{ t(`${copyKey}.${slug}.title`) }}</strong>
              <span>{{ t(`${copyKey}.${slug}.intro`) }}</span>
              <span class="guides-card__link">{{ t('regionalSeo.guidesUi.read') }} <ArrowRight :size="15" aria-hidden="true" /></span>
            </span>
          </LocalizedLink>
        </div>
      </section>

      <section class="guides-region" aria-labelledby="guide-region-title">
        <div>
          <h2 id="guide-region-title">{{ t('regionalSeo.countriesTitle') }}</h2>
          <p>{{ t('regionalSeo.regionalIntro') }}</p>
          <CountryDirectoryLinks compact />
        </div>
        <div class="guides-region__aside">
          <MapPin :size="24" aria-hidden="true" />
          <h3>{{ t('regionalSeo.regionalAsideTitle') }}</h3>
          <p>{{ t('regionalSeo.regionalAsideBody') }}</p>
          <div v-if="regionalCreators.length" class="guides-region__avatars" aria-hidden="true">
            <span v-for="creator in regionalCreators.slice(0, 6)" :key="creator.slug"><img :src="creator.avatarUrl || '/images/creator-placeholder.webp'" alt="" loading="lazy"></span>
          </div>
        </div>
      </section>
    </template>

    <template v-else>
      <section class="guides-article-hero">
        <p class="eyebrow">{{ t(forCreators ? 'regionalSeo.guidesUi.forCreators' : 'regionalSeo.guidesUi.forBrands') }} · {{ t('regionalSeo.guidesUi.guide') }}</p>
        <h1>{{ t(`${copyKey}.${guide}.title`) }}</h1>
        <p class="guides-hero__intro">{{ t(`${copyKey}.${guide}.intro`) }}</p>
        <p class="guides-article-hero__meta">
          <span><BookOpen :size="16" aria-hidden="true" />{{ t('regionalSeo.guidesUi.steps', { count: 3 }) }}</span>
          <span v-if="videos.length"><PlayCircle :size="16" aria-hidden="true" />{{ t('regionalSeo.guidesUi.withVideo') }}</span>
        </p>
      </section>

      <div class="guides-article">
        <nav class="guides-toc" :aria-label="t('regionalSeo.guidesUi.contents')">
          <small>{{ t('regionalSeo.guidesUi.contents') }}</small>
          <a
            v-for="index in 3"
            :key="index"
            :href="`#guide-step-${index}`"
            :aria-current="activeStep === index ? 'true' : undefined"
            @click.prevent="goToStep(index)"
          ><b>{{ index }}</b>{{ t(`${copyKey}.${guide}.sections.${index - 1}.heading`) }}</a>
        </nav>

        <article class="guides-steps">
          <GuideVideo v-for="video in videos" :key="video.id" :video="video" />
          <section v-for="index in 3" :id="`guide-step-${index}`" :key="`${guide}-${index}`" class="guides-step">
            <span class="guides-step__number" aria-hidden="true">{{ index }}</span>
            <div>
              <h2><component :is="stepIcons[guide][index - 1]" :size="20" aria-hidden="true" />{{ t(`${copyKey}.${guide}.sections.${index - 1}.heading`) }}</h2>
              <p>{{ t(`${copyKey}.${guide}.sections.${index - 1}.body`) }}</p>
            </div>
          </section>
          <section class="guides-next">
            <div>
              <strong>{{ t('regionalSeo.guidesUi.nextStepTitle') }}</strong>
              <p>{{ t(forCreators ? 'regionalSeo.creatorGuides.relatedIntro' : 'regionalSeo.guideRelatedIntro') }}</p>
            </div>
            <div class="guides-actions">
              <LocalizedLink class="button button--dark" :to="{ name: forCreators ? 'campaigns' : 'creators' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.browse' : 'regionalSeo.browse') }} <ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
              <LocalizedLink class="button button--tonal" :to="{ name: forCreators ? 'account' : 'account-campaign-create' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.profile' : 'regionalSeo.campaign') }}</LocalizedLink>
            </div>
          </section>
        </article>

        <aside class="guides-aside">
          <nav class="guides-related" :aria-label="t('regionalSeo.guidesUi.nextGuides')">
            <strong>{{ t('regionalSeo.guidesUi.nextGuides') }}</strong>
            <LocalizedLink v-for="slug in otherSlugs" :key="slug" :to="{ name: articleRoute, params: { guide: slug } }">{{ t(`${copyKey}.${slug}.title`) }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            <LocalizedLink :to="{ name: indexRoute }">{{ t('regionalSeo.back') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
          </nav>
          <section class="guides-help">
            <h3>{{ t('regionalSeo.guidesUi.helpTitle') }}</h3>
            <p>{{ t('regionalSeo.guidesUi.helpText') }}</p>
            <LocalizedLink class="button guides-help__button" :to="{ name: 'support' }">{{ t('support.label') }}</LocalizedLink>
          </section>
          <section class="guides-countries">
            <strong>{{ t('regionalSeo.countriesTitle') }}</strong>
            <CountryDirectoryLinks compact />
          </section>
        </aside>
      </div>
    </template>

    <div v-if="!guide" class="guides-actions guides-actions--page">
      <LocalizedLink class="button button--dark" :to="{ name: forCreators ? 'campaigns' : 'creators' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.browse' : 'regionalSeo.browse') }} <ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
      <LocalizedLink class="button button--tonal" :to="{ name: forCreators ? 'account' : 'account-campaign-create' }">{{ t(forCreators ? 'regionalSeo.creatorGuides.profile' : 'regionalSeo.campaign') }}</LocalizedLink>
      <LocalizedLink class="text-link" :to="{ name: 'how-it-works' }">{{ t('regionalSeo.howItWorks.title') }} <ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
    </div>
  </main>
  <NotFoundView v-else />
</template>

<style lang="scss" src="../scss/views/SeoGuidesView.scss"></style>
