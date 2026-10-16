<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pin, ArrowUpRight } from '@lucide/vue'
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
        <img :src="item.coverUrl || (item.type === 'image' ? item.url : '') || CREATOR_PLACEHOLDER" :alt="item.title || name" loading="lazy" />
        <span class="creator-works__copy">
          <span v-if="item.featured" class="creator-works__pin">
            <Pin :size="13" />
            {{ t('studio.featuredWork') }}
          </span>
          <small>{{ item.source === 'sample' ? t('studio.sample') : item.client || (item.platform === 'All' ? t('platforms.all') : item.platform) }}</small>
          <strong>{{ item.title }}</strong>
          <span>{{ item.summary }}</span>
          <span class="creator-works__link">
            {{ t('studio.workDetails') }}
            <ArrowUpRight :size="15" />
          </span>
        </span>
      </button>
    </div>
    <FormModal :open="Boolean(selected)" :title="selected?.title || name" @update:open="!$event && (selected = null)">
      <template v-if="selected">
        <p v-if="selected.client" class="creator-works__client">
          {{ selected.client }}
          <template v-if="selected.agency"> · {{ selected.agency }}</template>
        </p>
        <p v-if="selected.source">
          {{ t(`studio.${selected.source}`) }}
          <template v-if="selected.projectDate"> · {{ formatDate(selected.projectDate) }}</template>
        </p>
        <PortfolioGallery v-if="media.length" :items="media" :name="name" />
        <div class="creator-works__details">
          <div v-if="selected.brief">
            <h3>{{ t('studio.brief') }}</h3>
            <p>{{ selected.brief }}</p>
          </div>
          <div v-if="selected.contribution">
            <h3>{{ t('studio.contribution') }}</h3>
            <p>{{ selected.contribution }}</p>
          </div>
        </div>
        <ul v-if="selected.deliverables?.length">
          <li v-for="(row,index) in selected.deliverables" :key="index">
            {{ row.quantity }}
            ×
            {{ t(`studio.${row.format}`) }}
            <template v-if="row.duration"> · {{ row.duration }} {{ t('studio.seconds') }}</template>
          </li>
        </ul>
        <div v-if="selected.results?.length" class="creator-works__results">
          <div v-for="(result,index) in selected.results" :key="index">
            <strong>{{ result.value }}</strong>
            <span>{{ result.metric }} · {{ result.period }}</span>
          </div>
        </div>
        <p v-if="selected.results?.length" class="creator-works__source">
          {{ t('creatorProfile.selfReported') }}
          <template v-if="selected.measuredAt"> · {{ formatDate(selected.measuredAt) }}</template>
        </p>
        <a v-if="selected.postUrl" :href="selected.postUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.postUrl') }} ↗</a>
      </template>
      <template #actions="{close}">
        <button type="button" class="button button--outline" @click="close">{{ t('reviews.close') }}</button>
      </template>
    </FormModal>
  </section>
</template>
<style scoped lang="scss">
.creator-works { min-width:0; h2 { font-size:27px; margin:0; color:var(--forest); } header p { color:var(--muted); font-size:12px; margin:8px 0 16px; }
  &__grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  &__item { overflow:hidden; text-align:left; background:#fffefa; border:1px solid #dce2d9; padding:0; border-radius:15px; font:inherit; color:var(--forest); cursor:pointer; min-width:0; img { display:block; width:100%; height:155px; object-fit:cover; } &--featured { grid-column:1/-1; display:grid; grid-template-columns:1fr 1fr; img { height:240px; } } }
  &__copy { display:flex; flex-direction:column; gap:7px; padding:16px; justify-content:center; small, >span:not(.creator-works__link):not(.creator-works__pin) { color:var(--muted); font-size:12px; line-height:1.6; } >strong { font-size:17px; } }
  &__pin { display:flex; align-items:center; gap:5px; font-size:11px; } &__link { display:flex; align-items:center; gap:8px; font-size:12px; margin-top:7px; }
  &__details { display:grid; gap:16px; p { white-space:pre-wrap; overflow-wrap:anywhere; font-size:13px; line-height:1.7; } h3 { font-size:14px; } }
  &__results { display:flex; flex-wrap:wrap; gap:15px; padding:15px; background:#e5ebe2; border-radius:10px; strong,span { display:block; } span { font-size:12px; } } &__source { font-size:11px; color:var(--muted); }
  @media(max-width:760px) { &__item--featured { display:block; img { height:200px; min-height:0; } } &__copy { padding:12px; } }
}
</style>
