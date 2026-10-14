<script setup>
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '../../lib/api'

const props = defineProps({
  profile: { type: Object, required: true },
  options: { type: Object, required: true },
  works: { type: Array, default: () => [] },
  rating: { type: Object, default: null },
})
const { t, locale } = useI18n()
const types = computed(() => (props.profile.creatorTypes || []).map(type => t(`creatorTypes.options.${type}`)).join(' · '))
const location = computed(() => [types.value, props.profile.city].filter(Boolean).join(' · '))
const number = value => new Intl.NumberFormat(locale.value === 'bs' || locale.value === 'cnr' ? 'hr' : locale.value).format(value)
</script>

<template>
  <article class="creator-kit-paper" :aria-label="t('studio.previewPresentation')">
    <PresentationDemoBadge v-if="profile.isPresentationDemo" compact />
    <header class="creator-kit-paper__identity">
      <img v-if="profile.avatarUrl" :src="profile.avatarUrl" :alt="profile.displayName" />
      <div>
        <h3>{{ profile.displayName }}</h3>
        <p v-if="location">{{ location }}</p>
      </div>
    </header>
    <p v-if="profile.tagline">{{ profile.tagline }}</p>
    <div v-if="options.audience && profile.socialProfiles?.length" class="creator-kit-paper__section">
      <p v-for="(network, index) in profile.socialProfiles" :key="network.id || index">
        <strong>{{ network.platform }}</strong>
        ·
        {{ network.followers == null ? t('account.notProvided') : `${number(network.followers)} ${t('studio.followersUnit')}` }}
      </p>
    </div>
    <div v-if="works.length" class="creator-kit-paper__images">
      <figure v-for="work in works" :key="work.key || work.id">
        <img v-if="work.coverUrl || work.type === 'image' && work.url" :src="work.coverUrl || work.url" :alt="work.title" />
        <figcaption v-else>{{ work.title }}</figcaption>
      </figure>
    </div>
    <div v-if="options.packages && profile.packages?.length" class="creator-kit-paper__section">
      <p v-for="(item, index) in profile.packages" :key="item.id || index">
        {{ item.title }}
        <template v-if="options.prices">
          ·
          {{ item.priceMode === 'from' ? t('studio.priceFrom') : '' }}
          {{ item.priceMode === 'agreement' || !item.price ? t('creatorProfile.priceByAgreement') : formatMoney(item.price, item.currency) }}
        </template>
      </p>
    </div>
    <p v-if="options.reviews && rating?.reviewCount" class="creator-kit-paper__section">
      ★
      {{ number(rating.average) }}
      ·
      {{ t('reviews.ratingHint', { count: rating.reviewCount }) }}
    </p>
    <p v-if="profile.slug" class="creator-kit-paper__url">wave.ba/kreatori/{{ profile.slug }}</p>
  </article>
</template>
<style scoped lang="scss">
.creator-kit-paper {
  min-width: 0; padding: 20px; border: 1px solid #dce2d9; border-radius: 12px; background: var(--paper); overflow-wrap: anywhere;
  &__identity { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; }
  &__identity img { flex: 0 0 56px; width: 56px; height: 67px; object-fit: cover; object-position: 50% 20%; border-radius: 32px 32px 12px 12px; }
  h3 { margin: 0 0 4px; font-family: var(--serif); font-size: 24px; font-weight: 400; line-height: 1.2; color: var(--forest); }
  p { margin: 0; font-size: 12px; line-height: 1.7; color: var(--muted); }
  strong { font-weight: 500; }
  &__section { margin-top: 12px; }
  &__images { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 14px 0; }
  figure { min-width: 0; margin: 0; }
  &__images img { display: block; width: 100%; height: 100px; object-fit: cover; border-radius: 7px; }
  figcaption { display: grid; place-items: center; min-height: 100px; padding: 12px; border-radius: 7px; background: #e5ebe2; color: var(--forest); font-size: 12px; }
  p.creator-kit-paper__url { margin-top: 14px; color: var(--forest); }
}
</style>
