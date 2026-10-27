<script setup>
import { Image, Video, ArrowRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import PortfolioGallery from '../shared/PortfolioGallery.vue'
import { referenceImage } from '../../lib/creatorPresentation'
const props = defineProps({ items: { type: Array, required: true }, name: { type: String, required: true }, coverUrl: { type: String, default: '' } })
const { t } = useI18n()
const hostname = url => { try { return new URL(url, 'https://wave.ba').hostname.replace(/^www\./, '') } catch { return '' } }
function preview(row, index) {
  return {
    ...row,
    id: `reference-${index}`,
    url: referenceImage(row) || row.url,
    coverUrl: row.coverUrl || (index === 0 && row.type === 'video' ? props.coverUrl : ''),
  }
}
</script>

<template>
  <section v-if="items.length" class="work-references" :aria-label="t('studio.references')">
    <h3>{{ t('studio.references') }}</h3>
    <article v-for="(row, index) in items" :key="index" class="work-references__card">
      <header>
        <span><Image v-if="row.type === 'image'" :size="18" aria-hidden="true" /><Video v-else :size="18" aria-hidden="true" />{{ row.platform && row.platform !== 'All' ? (row.platform === 'Other' ? t('studio.otherPlatform') : row.platform) : t('studio.referenceNumber', { number: index + 1 }) }}</span>
        <small>{{ t(row.type === 'image' ? 'account.image' : 'account.video') }}</small>
      </header>
      <PortfolioGallery v-if="referenceImage(row) || row.embedUrl" :items="[preview(row, index)]" :name="name" showcase />
      <a v-if="row.url || referenceImage(row)" :href="row.url || referenceImage(row)" target="_blank" rel="noopener noreferrer" class="work-references__link">
        <span>{{ t('studio.viewReference') }}<small>{{ hostname(row.url || referenceImage(row)) }}</small></span><ArrowRight :size="18" aria-hidden="true" />
      </a>
    </article>
  </section>
</template>

<style scoped lang="scss">
.work-references {
  display: grid; gap: 12px; min-width: 0;
  > h3 { margin: 0; }
  &__card { display: grid; gap: 12px; min-width: 0; padding: 14px; border: 1px solid var(--line); border-radius: 14px; background: var(--paper); }
  header, header > span { display: flex; align-items: center; gap: 8px; min-width: 0; }
  header { justify-content: space-between; color: var(--forest); font-weight: 600; }
  header small { color: var(--muted-text); font-size: 12px; font-weight: 400; }
  svg { flex-shrink: 0; }
  &__link { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 44px; color: var(--forest); text-decoration: none; font-weight: 600; }
  &__link span { display: grid; gap: 2px; min-width: 0; overflow-wrap: anywhere; }
  &__link small { color: var(--muted-text); font-size: 12px; font-weight: 400; }
}
</style>
