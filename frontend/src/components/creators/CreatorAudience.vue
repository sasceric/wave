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
.creator-audience {
  min-width: 0;
  header { margin-bottom: 14px; h2 { font-size: 25px; margin: 0; color: var(--forest); } p { margin: 5px 0 0; color: var(--muted); font-size: 12px; } }
  &__surface { padding: 18px; border: 1px solid #dce2d9; border-radius: 14px; background: #fffefa; }
  &--embedded &__surface { border: 0; border-radius: 0; padding: 0; background: transparent; }
  &__channel { font-size: 12px; margin: 0 0 16px; overflow-wrap: anywhere; a { display: inline-flex; align-items: center; gap: 6px; color: var(--forest); } }
  &__platform-icon { display: inline-block; flex: 0 0 16px; width: 16px; height: 16px; background: currentColor; mask: var(--platform-icon) center / contain no-repeat; }
  &__tabs { display: flex; flex-wrap: wrap; gap: 7px; margin: 0 0 16px; button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #dce2d9; background: transparent; color: var(--muted); border-radius: 8px; padding: 6px 10px; min-height: 34px; font: inherit; font-size: 12px; cursor: pointer; &[aria-pressed=true] { background: var(--forest); border-color: var(--forest); color: #fffefa; } } }
  &__metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding-bottom: 17px; border-bottom: 1px solid #dce2d9; strong { display: block; font-size: 24px; font-weight: 500; color: var(--forest); line-height: 1.2; } span { display: block; font-size: 11px; color: var(--muted); margin-top: 5px; } }
  &__lower { display: grid; grid-template-columns: 1.2fr 1fr; gap: 26px; padding-top: 15px; h3 { margin: 0 0 10px; font: 600 12px/1.5 'DM Sans', sans-serif; color: var(--forest); } }
  &__countries { min-width: 0; p { display: flex; justify-content: space-between; gap: 8px; font-size: 11px; margin: 9px 0 4px; } strong { font-weight: 500; } }
  &__track { display: block; height: 5px; background: #e5ebe2; border-radius: 5px; span { display: block; height: 100%; background: var(--forest); border-radius: 5px; } }
  &__notes { min-width: 0; p { margin: 0 0 8px; color: var(--muted); font-size: 12px; line-height: 1.7; } strong { color: var(--forest); font-weight: 500; } }
  &__source { font-size: 11px; line-height: 1.6; color: var(--muted); margin: 12px 0 0; }
  &__attribution { display: flex; align-items: flex-start; gap: 5px; svg { flex: 0 0 13px; margin-top: 2px; } }
  @media (max-width: 760px) { &__surface { padding: 16px; } &__tabs button { min-height: 44px; } &__lower { grid-template-columns: minmax(0, 1fr); gap: 18px; } &__metrics { gap: 10px; strong { font-size: 22px; overflow-wrap: anywhere; } } }
}
</style>
