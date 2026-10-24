<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pin, ArrowUpRight, BriefcaseBusiness, CalendarDays, Clapperboard } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import PortfolioGallery from '../shared/PortfolioGallery.vue'
import { formatDate } from '../../lib/api'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'
defineProps({ items: { type: Array, default: () => [] }, name: { type: String, required: true }, showHeading: { type: Boolean, default: true } })
const { t } = useI18n()
const selected = ref(null)
const media = computed(() => selected.value ? [{ ...selected.value, id: 'main' }, ...(selected.value.media || []).map((item, index) => ({ ...item, id: `extra-${index}`, title: selected.value.title }))].filter(item => item.url || item.embedUrl) : [])
</script>
<template>
  <section v-if="items.length" class="creator-works">
    <header v-if="showHeading">
      <h2>{{ t('studio.selectedWorks') }}</h2>
      <p>{{ t('studio.worksIntro') }}</p>
    </header>
    <div class="creator-works__grid">
      <button
        v-for="item in [...items].sort((a,b) => Number(Boolean(b.featured))-Number(Boolean(a.featured)))"
        :key="item.id"
        type="button"
        :class="['creator-works__item', {'creator-works__item--featured':item.featured}]"
        @click="selected = item"
      >
        <span class="creator-works__visual">
          <img :src="item.coverUrl || (item.type === 'image' ? item.url : '') || CREATOR_PLACEHOLDER" :alt="item.title || name" loading="lazy" />
          <span v-if="item.platform && item.platform !== 'All'" class="creator-works__chip">{{ item.platform }}</span>
          <span v-if="item.featured" class="creator-works__pin">
            <Pin :size="12" aria-hidden="true" />
            {{ t('studio.featuredWork') }}
          </span>
        </span>
        <span class="creator-works__copy">
          <small>{{ item.source === 'sample' ? t('studio.sample') : item.client || '' }}</small>
          <strong>{{ item.title }}</strong>
          <span class="creator-works__summary">{{ item.summary }}</span>
          <span class="creator-works__link">
            {{ t('studio.workDetails') }}
            <ArrowUpRight :size="15" />
          </span>
        </span>
      </button>
    </div>
    <FormModal :open="Boolean(selected)" :title="selected?.title || name" wide @update:open="!$event && (selected = null)">
      <template v-if="selected">
        <div class="work-detail">
          <div class="work-detail__meta">
            <span v-if="selected.client && selected.client !== selected.title || selected.agency" class="work-detail__client">
              <template v-if="selected.client && selected.client !== selected.title">{{ selected.client }}</template>
              <template v-if="selected.agency"><span v-if="selected.client && selected.client !== selected.title"> · </span>{{ selected.agency }}</template>
            </span>
            <span v-if="selected.source" class="work-detail__source">
              <BriefcaseBusiness :size="14" aria-hidden="true" />
              {{ t(`studio.${selected.source}`) }}
            </span>
            <span v-if="selected.projectDate">
              <CalendarDays :size="14" aria-hidden="true" />
              {{ formatDate(selected.projectDate) }}
            </span>
          </div>
          <PortfolioGallery v-if="media.length" :items="media" :name="name" showcase />
          <p v-if="selected.summary && selected.summary !== selected.title && selected.summary !== selected.client" class="work-detail__summary">{{ selected.summary }}</p>
          <div v-if="selected.brief || selected.contribution" class="work-detail__sections">
            <section v-if="selected.brief">
              <h3>{{ t('studio.brief') }}</h3>
              <p>{{ selected.brief }}</p>
            </section>
            <section v-if="selected.contribution">
              <h3>{{ t('studio.contribution') }}</h3>
              <p>{{ selected.contribution }}</p>
            </section>
          </div>
          <section v-if="selected.deliverables?.length" class="work-detail__deliverables">
            <h3>{{ t('studio.deliverables') }}</h3>
            <ul>
              <li v-for="(row,index) in selected.deliverables" :key="index">
                <Clapperboard :size="15" aria-hidden="true" />
                {{ row.quantity }} × {{ t(`studio.${row.format}`) }}
                <template v-if="row.duration"> · {{ row.duration }} {{ t('studio.seconds') }}</template>
              </li>
            </ul>
          </section>
          <section v-if="selected.results?.length" class="work-detail__results-section">
            <h3>{{ t('studio.resultsTitle') }}</h3>
            <div class="work-detail__results">
              <div v-for="(result,index) in selected.results" :key="index">
                <strong>{{ result.value }}</strong>
                <span>{{ result.metric }}</span>
                <small v-if="result.period">{{ result.period }}</small>
              </div>
            </div>
            <p class="work-detail__note">
              {{ t('creatorProfile.selfReported') }}
              <template v-if="selected.measuredAt"> · {{ formatDate(selected.measuredAt) }}</template>
            </p>
          </section>
        </div>
      </template>
      <template #actions="{close}">
        <a v-if="selected?.postUrl" class="button button--dark" :href="selected.postUrl" target="_blank" rel="noopener noreferrer">
          {{ t('studio.viewPost') }}
          <ArrowUpRight :size="16" aria-hidden="true" />
        </a>
        <button type="button" class="button button--outline" @click="close">{{ t('reviews.close') }}</button>
      </template>
    </FormModal>
  </section>
</template>
<style scoped lang="scss">
@use '../../scss/public-profile-layout';

// Works grid from the profile prototype: the first work spans the row, the rest sit two per row.
// Thumbnail heights (155/240px, phone 135/200px) are kept as before, matching the upload hints.
.creator-works { display: grid; gap: 16px; min-width: 0;
  h2 { @include public-profile-layout.section-title; }
  header p { @include public-profile-layout.section-intro; }
  &__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  &__item { @include public-profile-layout.card; display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; min-width: 0; overflow: hidden; padding: 0; color: var(--ink); font: inherit; text-align: left; cursor: pointer; transition: box-shadow .2s var(--ease-standard);
    &:hover { box-shadow: var(--shadow-soft); }
    &:focus-visible { outline: 2px solid var(--forest); outline-offset: 3px; }
  }
  &__visual { position: relative; display: block; flex: none; min-width: 0; height: 155px; overflow: hidden; background: var(--surface);
    img { position: absolute; inset: 0; display: block; width: 100%; height: 100%; object-fit: cover; }
  }
  // With an odd number of works the first spans the row so the grid has no gap.
  &__item:first-child:nth-last-child(odd) { grid-column: 1 / -1; display: grid; grid-template-columns: 1.2fr 1fr;
    .creator-works__visual { height: 240px; }
  }
  &__chip, &__pin { position: absolute; left: 12px; display: inline-flex; align-items: center; gap: 5px; max-width: calc(100% - 24px); padding: 5px 11px; border: 1px solid var(--glass-edge); border-radius: var(--radius-pill); color: var(--ink); background: var(--glass-fill-strong); box-shadow: var(--glass-highlight); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); font-size: 13px; font-weight: 600; line-height: 1.3; svg { flex-shrink: 0; } }
  &__chip { top: 12px; }
  &__pin { bottom: 12px; }
  &__copy { display: flex; flex: 1; flex-direction: column; gap: 6px; min-width: 0; padding: 18px 20px 20px;
    small { display: -webkit-box; overflow: hidden; color: var(--muted-text); font-size: 13px; -webkit-box-orient: vertical; -webkit-line-clamp: 1; }
    > strong { display: -webkit-box; overflow: hidden; font-size: 18px; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
  }
  &__summary { display: -webkit-box; overflow: hidden; color: var(--muted-text); font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
  &__link { display: inline-flex; align-items: center; gap: 6px; margin-top: auto; padding-top: 10px; color: var(--forest); font-size: 14px; font-weight: 600; }
  @media (max-width: 760px) {
    &__visual { height: 135px; }
    &__item:first-child:nth-last-child(odd) { display: flex; .creator-works__visual { height: 200px; } }
  }
}
</style>

<style scoped lang="scss" src="../../scss/components/creators/WorkDetail.scss"></style>
