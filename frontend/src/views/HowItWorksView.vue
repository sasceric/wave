<script setup>
import { useI18n } from 'vue-i18n'
import { ArrowRight, Building2, ChartNoAxesColumnIncreasing, Heart, UserRound } from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SeoHeroArtwork from '../components/shared/SeoHeroArtwork.vue'
const { t } = useI18n()
const audiences = ['creators', 'companies']
const audienceImages = { creators: '/images/how-it-works-creator.webp', companies: '/images/how-it-works-company.webp' }
</script>

<template>
  <section class="page-width seo-content how-it-works-page">
    <div class="seo-content__hero how-it-works-page__hero">
      <div>
        <p class="eyebrow">Wave</p>
        <h1>{{ t('regionalSeo.howItWorks.title') }}</h1>
        <p class="seo-content__intro">{{ t('regionalSeo.howItWorks.intro') }}</p>
      </div>
      <SeoHeroArtwork />
    </div>
    <svg class="how-it-works-page__masks" width="0" height="0" aria-hidden="true" focusable="false">
      <defs>
        <clipPath id="how-creator-photo" clipPathUnits="objectBoundingBox">
          <path d="M.16 0C.02 0 .02 .06 .065 .12C.16 .24 .12 .30 .055 .36C-.03 .43 .015 .53 .035 .60C.10 .75 -.055 .85 .055 .93C.12 .99 .23 .965 .32 .96C.49 .93 .55 .95 .68 .98C.78 1.01 .91 1 .97 .97C1 .95 1 .91 1 .87L1 .025Q1 0 .96 0Z" />
        </clipPath>
        <clipPath id="how-company-photo" clipPathUnits="objectBoundingBox">
          <path d="M.16 0C.04 0 .005 .06 .01 .15C.02 .31 .10 .32 .12 .43C.15 .57 .07 .61 .14 .76C.21 .93 .19 1 .36 .99C.53 .96 .63 .95 .79 .98C.90 1.015 1 .99 1 .92L1 .025Q1 0 .96 0Z" />
        </clipPath>
      </defs>
    </svg>
    <div class="how-it-works-page__audiences">
      <section v-for="audience in audiences" :key="audience" class="how-it-works-page__card" :class="`how-it-works-page__card--${audience}`" :aria-labelledby="`how-${audience}`">
        <div class="how-it-works-page__card-copy">
          <span class="how-it-works-page__role-icon"><component :is="audience === 'creators' ? UserRound : Building2" :size="26" aria-hidden="true" /></span>
          <h2 :id="`how-${audience}`">{{ t(`regionalSeo.howItWorks.${audience}.title`) }}</h2>
          <p class="how-it-works-page__card-intro">{{ t(`regionalSeo.howItWorks.${audience}.intro`) }}</p>
          <ol>
            <li v-for="index in 3" :key="index">
              <span class="how-it-works-page__step-number" aria-hidden="true">{{ index }}</span>
              <div>
                <h3>{{ t(`regionalSeo.howItWorks.${audience}.steps.${index - 1}.heading`) }}</h3>
                <p>{{ t(`regionalSeo.howItWorks.${audience}.steps.${index - 1}.body`) }}</p>
              </div>
            </li>
          </ol>
          <LocalizedLink class="button" :class="audience === 'creators' ? 'button--dark' : 'button--outline'" :to="{ name: audience === 'creators' ? 'creator-guides' : 'seo-guides' }">{{ t(`regionalSeo.howItWorks.${audience}.guides`) }} <ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
        </div>
        <div class="how-it-works-page__visual" aria-hidden="true">
          <img :src="audienceImages[audience]" alt="" width="600" height="900" loading="lazy">
          <span class="how-it-works-page__floating-icon"><ChartNoAxesColumnIncreasing :size="29" /></span>
          <span class="how-it-works-page__floating-icon how-it-works-page__floating-icon--small"><component :is="audience === 'creators' ? Heart : UserRound" :size="23" /></span>
        </div>
      </section>
    </div>
    <section class="how-it-works-page__start">
      <h2>{{ t('regionalSeo.howItWorks.startTitle') }}</h2>
      <p>{{ t('regionalSeo.howItWorks.startIntro') }}</p>
      <div class="seo-content__actions">
        <LocalizedLink class="button button--dark" :to="{ name: 'account' }">{{ t('regionalSeo.howItWorks.start') }} <ArrowRight :size="17" aria-hidden="true" /></LocalizedLink>
        <LocalizedLink class="button button--outline" :to="{ name: 'creators' }">{{ t('regionalSeo.browse') }}</LocalizedLink>
        <LocalizedLink class="button button--outline" :to="{ name: 'campaigns' }">{{ t('regionalSeo.creatorGuides.browse') }}</LocalizedLink>
      </div>
    </section>
  </section>
</template>

<style lang="scss" src="../scss/components/shared/SeoContent.scss"></style>
<style lang="scss" src="../scss/views/HowItWorksView.scss"></style>
