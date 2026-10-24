<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { languageOptions } from '../../lib/creatorPresentation'
import { formatDate } from '../../lib/api'
import { ExternalLink, Info, Podcast, Radio } from '@lucide/vue'
import instagramIcon from '../../../../public/images/instagram.svg?url'
import tiktokIcon from '../../../../public/images/tiktok.svg?url'
import youtubeIcon from '../../../../public/images/youtube.svg?url'
defineProps({ profiles: { type: Array, default: () => [] }, demo: Boolean, showHeading: { type: Boolean, default: true }, languages: { type: Array, default: () => [] }, description: { type: String, default: '' } })
const active = ref(0)
const brandIcons = { Instagram: instagramIcon, TikTok: tiktokIcon, YouTube: youtubeIcon }
const platformIcons = { Twitch: Radio, Spotify: Podcast }
const { t, te, locale } = useI18n()
const names = computed(() => new Intl.DisplayNames([locale.value === 'cnr' ? 'bs' : locale.value === 'sr' ? 'sr-Latn' : locale.value], { type: 'region' }))
const number = value => value === null || value === undefined || value === '' ? '—' : new Intl.NumberFormat(locale.value === 'bs' || locale.value === 'cnr' ? 'hr' : locale.value).format(value)
function channelUrl(profile) {
    const handle = profile.handle?.trim() || ''
    if (handle.startsWith('https://')) {
        try { return new URL(handle).href } catch { return null }
    }
    if (!handle || handle.includes('://')) return null
    const name = encodeURIComponent(handle.replace(/^@/, ''))
    return {
        Instagram: `https://www.instagram.com/${name}/`,
        TikTok: `https://www.tiktok.com/@${name}`,
        YouTube: `https://www.youtube.com/@${name}`,
        Twitch: `https://www.twitch.tv/${name}`,
    }[profile.platform] || null
}
</script>
<template>
  <section v-if="profiles.length" class="creator-audience" :class="{ 'creator-audience--embedded': !showHeading }">
    <header v-if="showHeading">
      <h2>{{ t('studio.publicAudience') }}</h2>
      <p>{{ t('studio.publicAudienceIntro') }}</p>
    </header>
    <div class="creator-audience__surface">
      <div class="creator-audience__tabs" :aria-label="t('account.platform')">
        <button v-for="(profile,index) in profiles" :key="index" type="button" :aria-pressed="active === index" @click="active = index">
          <span v-if="brandIcons[profile.platform]" class="creator-audience__platform-icon" :style="{ '--platform-icon': `url(${JSON.stringify(brandIcons[profile.platform])})` }" aria-hidden="true"></span>
          <component :is="platformIcons[profile.platform]" v-else-if="platformIcons[profile.platform]" :size="16" aria-hidden="true" />
          {{ profile.platform }}
        </button>
      </div>
      <div v-for="(profile,index) in profiles" v-show="index === Math.min(active, profiles.length-1)" :key="index">
        <p class="creator-audience__channel">
          <a v-if="!demo && channelUrl(profile)" :href="channelUrl(profile)" target="_blank" rel="noopener noreferrer nofollow ugc">
            {{ profile.handle }}
            <ExternalLink :size="13" aria-hidden="true" />
          </a>
          <span v-else>{{ profile.handle }}</span>
        </p>
        <div class="creator-audience__metrics">
          <div v-if="profile.followers != null">
            <strong>{{ number(profile.followers) }}</strong>
            <span>{{ t('account.followers') }}</span>
          </div>
          <div v-if="profile.averageViews != null">
            <strong>{{ number(profile.averageViews) }}</strong>
            <span>
              {{ t(profile.platform === 'Spotify' ? 'studio.averagePlays' : profile.platform === 'Twitch' ? 'studio.averageLive' : 'studio.averageViews') }}
            </span>
          </div>
          <div v-if="profile.engagementRate != null">
            <strong>{{ number(profile.engagementRate) }}%</strong>
            <span>{{ t('studio.engagement') }}</span>
          </div>
        </div>
        <div
          v-if="profile.audienceCountries?.length || languages.length || description || profile.averageViews != null || profile.engagementRate != null"
          class="creator-audience__lower"
        >
          <div v-if="profile.audienceCountries?.length" class="creator-audience__countries">
            <h3>{{ t('studio.countries') }}</h3>
            <div v-for="country in profile.audienceCountries" :key="country.countryCode">
              <p>
                <span>
                  {{ te(`companyDirectory.countryNames.${country.countryCode}`) ? t(`companyDirectory.countryNames.${country.countryCode}`) : names.of(country.countryCode) }}
                </span>
                <strong>{{ number(country.percent) }}%</strong>
              </p>
              <span class="creator-audience__track" aria-hidden="true">
                <span :style="{width:`${country.percent}%`}"></span>
              </span>
            </div>
          </div>
          <div class="creator-audience__notes">
            <template v-if="languages.length || description">
              <h3>{{ t('studio.languageAndContent') }}</h3>
              <p v-if="languages.length">
                <strong>
                  {{ languages.map(code => languageOptions(locale, t).find(option => option.value === code)?.label || code).join(' · ') }}
                </strong>
              </p>
              <p v-if="description">{{ description }}</p>
            </template>
            <p v-if="profile.averageViews != null" class="creator-audience__source">
              {{ t('studio.sampleDescription', { count: profile.sampleSize || 10 }) }}
            </p>
            <p v-if="profile.engagementRate != null" class="creator-audience__source">
              {{ t(`studio.basis_${profile.engagementBasis || 'followers'}`) }}
            </p>
          </div>
        </div>
        <p class="creator-audience__source creator-audience__attribution">
          <Info :size="13" aria-hidden="true" />
          <span>
            {{ t('creatorProfile.selfReported') }}
            <template v-if="profile.lastUpdated"> · {{ t('creatorProfile.updated', { date:formatDate(profile.lastUpdated) }) }}</template>
          </span>
        </p>
      </div>
    </div>
  </section>
</template>
<style scoped lang="scss">
@use '../../scss/public-profile-layout';

// Audience card from the profile prototype: segmented platform switch, metric tiles, country bars.
.creator-audience {
  display: grid; gap: 16px; min-width: 0;
  header { h2 { @include public-profile-layout.section-title; } p { @include public-profile-layout.section-intro; } }
  &__surface { @include public-profile-layout.card; display: grid; gap: 18px; padding: 22px; > div:not(.creator-audience__tabs) { display: grid; gap: 18px; min-width: 0; } }
  &--embedded &__surface { padding: 0; border: 0; border-radius: 0; background: transparent; }
  &__tabs { display: inline-flex; justify-self: start; max-width: 100%; gap: 2px; margin: 0; padding: 3px; overflow-x: auto; border-radius: var(--radius-pill); background: var(--surface); scrollbar-width: none;
    &::-webkit-scrollbar { display: none; }
    button { display: inline-flex; flex: none; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 0 16px; border: 0; border-radius: var(--radius-pill); color: var(--muted-text); background: transparent; font: inherit; font-size: 14px; font-weight: 500; white-space: nowrap; cursor: pointer;
      &[aria-pressed=true] { color: var(--ink); background: var(--card); box-shadow: 0 1px 3px rgba(23, 61, 54, .12); font-weight: 600; }
      &:focus-visible { outline: 2px solid var(--forest); outline-offset: 1px; }
    }
  }
  &__platform-icon { display: inline-block; flex: 0 0 16px; width: 16px; height: 16px; background: currentColor; mask: var(--platform-icon) center / contain no-repeat; }
  &__channel { margin: 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; a { display: inline-flex; align-items: center; gap: 6px; color: var(--forest); } }
  &__metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;
    > div { display: flex; flex-direction: column-reverse; justify-content: flex-end; gap: 2px; min-width: 0; padding: 16px; border-radius: 18px; background: var(--paper); }
    strong { display: block; color: var(--ink); font-size: 28px; font-variant-numeric: tabular-nums; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
    span { display: block; color: var(--muted-text); font-size: 13px; }
  }
  &__lower { display: grid; grid-template-columns: 1.2fr 1fr; gap: 24px; h3 { margin: 0 0 10px; color: var(--ink); font: 600 14px/1.5 var(--font-body, 'DM Sans', sans-serif); } }
  &__countries { display: grid; gap: 10px; min-width: 0;
    > div { display: grid; grid-template-columns: 110px minmax(0, 1fr) 44px; align-items: center; gap: 12px; font-size: 14px; }
    p { display: contents; }
    p strong { order: 3; color: var(--muted-text); font-variant-numeric: tabular-nums; font-weight: 400; text-align: right; }
    p span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  }
  &__track { order: 2; display: block; height: 8px; overflow: hidden; border-radius: var(--radius-pill); background: var(--surface); span { display: block; height: 100%; border-radius: inherit; background: var(--forest); } }
  &__notes { min-width: 0; p { margin: 0 0 8px; color: var(--muted-text); font-size: 14px; line-height: 1.6; } strong { color: var(--ink); font-weight: 600; } }
  &__source { margin: 0; color: var(--muted-text); font-size: 13px; line-height: 1.5; }
  &__attribution { display: flex; align-items: center; gap: 6px; svg { flex: 0 0 13px; } }
  @media (max-width: 760px) {
    &__surface { padding: 18px; }
    &__metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); strong { font-size: 24px; } }
    &__lower { grid-template-columns: minmax(0, 1fr); gap: 18px; }
    &__countries > div { grid-template-columns: 86px minmax(0, 1fr) 40px; }
    &__tabs button { min-height: 40px; }
  }
}
</style>
