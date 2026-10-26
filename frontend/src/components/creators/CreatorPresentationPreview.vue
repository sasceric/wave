<script setup>
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { workImage } from '../../lib/creatorPresentation'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({ profile: { type: Object, required: true } })
const { t, locale } = useI18n()
const channel = computed(() => props.profile.socialProfiles
  .filter(item => item.followers != null)
  .reduce((largest, item) => !largest || item.followers > largest.followers ? item : largest, null))
const images = computed(() => props.profile.portfolio
  .filter(item => item.publicPermission !== false && workImage(item))
  .slice(0, 4))
const types = computed(() => (props.profile.creatorTypes || []).map(type => t(`creatorTypes.options.${type}`)).join(' · '))
const number = value => new Intl.NumberFormat(locale.value === 'bs' ? 'hr' : locale.value, { maximumFractionDigits: 1 }).format(value)
const rating = computed(() => props.profile.rating?.average == null ? '—' : number(props.profile.rating.average))
</script>

<template>
  <section class="creator-presentation-preview">
    <div class="creator-presentation-preview__paper">
      <PresentationDemoBadge v-if="profile.isPresentationDemo" compact />
      <header>
        <img :src="profile.avatarUrl || CREATOR_PLACEHOLDER" :alt="profile.displayName" />
        <div>
          <h3>{{ profile.displayName }}</h3>
          <p>{{ [types, profile.city].filter(Boolean).join(' · ') }}</p>
        </div>
      </header>
      <p v-if="profile.tagline" class="creator-presentation-preview__intro">{{ profile.tagline }}</p>
      <dl class="creator-presentation-preview__metrics">
        <div v-if="channel">
          <dt>{{ channel.platform }} · {{ t('account.followers') }}</dt>
          <dd>{{ number(channel.followers) }}</dd>
        </div>
        <div>
          <dt>{{ t('creatorProfile.averageRating') }}</dt>
          <dd>{{ rating }}</dd>
        </div>
        <div>
          <dt>{{ t('creatorProfile.completedCollaborations') }}</dt>
          <dd>{{ profile.rating?.completedCampaigns || 0 }}</dd>
        </div>
      </dl>
      <div v-if="images.length" class="creator-presentation-preview__gallery">
        <img v-for="work in images" :key="work.id" :src="workImage(work)" :alt="work.title" loading="lazy" />
      </div>
      <p class="creator-presentation-preview__sections">{{ t('creatorProfile.presentationSections') }}</p>
      <p class="creator-presentation-preview__url">wave.ba/kreatori/{{ profile.slug }}</p>
    </div>
    <p class="creator-presentation-preview__note">{{ t('creatorProfile.presentationNote') }}</p>
  </section>
</template>
<style scoped lang="scss">
.creator-presentation-preview {
  min-width: 0;
  &__paper { padding: 20px; background: var(--paper); border: 1px solid #dce2d9; border-radius: 12px; }
  header { display: flex; gap: 14px; align-items: center; margin-bottom: 18px; }
  header > img { flex: 0 0 64px; width: 64px; height: 75px; border-radius: 35px 35px 12px 12px; object-fit: cover; }
  h3 { margin: 0; font: 400 27px/1.2 var(--serif); color: var(--forest); overflow-wrap: anywhere; }
  p { margin: 7px 0 0; font-size: 12px; color: var(--muted); line-height: 1.7; }
  &__metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 18px 0; }
  &__metrics > div { display: flex; flex-direction: column-reverse; gap: 5px; }
  dt { font-size: 11px; color: var(--muted); }
  dd { margin: 0; font-size: 24px; color: var(--forest); }
  &__gallery { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; img { width: 100%; height: 125px; object-fit: cover; border-radius: 8px; } }
  &__url { overflow-wrap: anywhere; }
  @media(max-width: 600px) { &__paper { padding: 16px; } h3 { font-size: 23px; } dd { font-size: 21px; } &__metrics { gap: 8px; } &__gallery img { height: 100px; } }
}
</style>
