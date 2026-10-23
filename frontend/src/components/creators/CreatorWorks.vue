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
          <span v-if="item.featured" class="creator-works__pin">
            <Pin :size="12" aria-hidden="true" />
            {{ t('studio.featuredWork') }}
          </span>
        </span>
        <span class="creator-works__copy">
          <small>{{ item.source === 'sample' ? t('studio.sample') : item.client || (item.platform && item.platform !== 'All' ? item.platform : '') }}</small>
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
.creator-works { min-width:0; h2 { font-size:27px; margin:0; color:var(--forest); } header p { color:var(--muted); font-size:12px; margin:8px 0 16px; }
  &__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
  &__item { display:flex; flex-direction:column; align-items:stretch; justify-content:flex-start; overflow:hidden; text-align:left; background:#fffefa; border:1px solid #dce2d9; padding:0; border-radius:15px; font:inherit; color:var(--forest); cursor:pointer; min-width:0; }
  &__visual { position:relative; display:block; flex:0 0 auto; min-width:0; height:155px; overflow:hidden; img { display:block; width:100%; height:100%; object-fit:cover; } }
  &__item:only-child, &__item:first-child:nth-last-child(3) { grid-column:1/-1; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); .creator-works__visual { height:240px; } }
  &__copy { display:flex; flex:1; min-width:0; flex-direction:column; gap:7px; padding:16px; small, >span:not(.creator-works__link):not(.creator-works__pin) { color:var(--muted); font-size:12px; line-height:1.6; } small { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:1; min-height:1.6em; overflow:hidden; } >strong { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; min-height:2.8em; overflow:hidden; overflow-wrap:anywhere; font-size:17px; line-height:1.4; } }
  &__summary { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; min-height:3.2em; overflow:hidden; overflow-wrap:anywhere; }
  &__pin { position:absolute; bottom:10px; left:10px; display:inline-flex; align-items:center; gap:5px; max-width:calc(100% - 20px); min-height:25px; padding:4px 8px; border:1px solid #ffffffb3; border-radius:8px; color:var(--forest); background:#fffefaf0; box-shadow:0 2px 8px #1f2a2814; font-size:10px; line-height:1.4; text-align:left; svg { flex-shrink:0; } } &__link { display:flex; align-items:center; gap:8px; font-size:12px; margin-top:auto; padding-top:7px; }
  @media(max-width:760px) {
    &__visual { height:135px; }
    &__item:only-child, &__item:first-child:nth-last-child(3) { display:block; .creator-works__visual { height:200px; } }
    &__copy { padding:12px; }
  }
}
</style>

<style scoped lang="scss" src="../../scss/components/creators/WorkDetail.scss"></style>
