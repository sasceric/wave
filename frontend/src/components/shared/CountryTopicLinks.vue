<script setup>
import { ArrowRight } from '@lucide/vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import { apiGet } from '../../lib/api'
import { countries, topicCopy, topicForSlug } from '../../lib/regionalSeo'
import { localizedPath } from '../../routePaths'

// Internal links to populated landing pages (areas, UGC, cities) of one country.
const props = defineProps({
  countryCode: { type: String, required: true },
  current: { type: String, default: '' },
})
const { t, locale } = useI18n()
const items = ref([])
const links = computed(() => items.value
  .filter(item => item.slug !== props.current)
  .map(item => {
    const topic = topicForSlug(props.countryCode, item.slug)
    return topic && { ...item, title: topicCopy(t, locale.value, props.countryCode, topic).title, to: localizedPath('country-topic', locale.value, { country: countries[props.countryCode].slug, topic: item.slug }) }
  })
  .filter(Boolean))
const groups = computed(() => [
  { key: 'category', heading: t('regionalSeo.topics.byCategory'), links: links.value.filter(link => link.kind !== 'city') },
  { key: 'city', heading: t('regionalSeo.topics.byCity'), links: links.value.filter(link => link.kind === 'city') },
].filter(group => group.links.length))

async function load() {
  try {
    const response = await apiGet(`/creators/landing-pages?country=${encodeURIComponent(props.countryCode)}`, { locale: locale.value })
    items.value = Array.isArray(response.data) ? response.data : []
  } catch {
    // Optional discovery links never replace the page with an error.
    items.value = []
  }
}
onMounted(load)
watch(() => props.countryCode, load)
</script>

<template>
  <nav v-if="groups.length || current" class="seo-topic-links" :aria-label="t('regionalSeo.topics.byCategory')">
    <div v-for="group in groups" :key="group.key" class="seo-topic-links__group">
      <h2>{{ group.heading }}</h2>
      <ul>
        <li v-for="link in group.links" :key="link.slug">
          <LocalizedLink :to="link.to">{{ link.title }}<ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></LocalizedLink>
        </li>
      </ul>
    </div>
    <p v-if="current" class="seo-topic-links__all">
      <LocalizedLink :to="localizedPath('country-creators', locale, { country: countries[countryCode].slug })">{{ t('regionalSeo.topics.allIn', { in: t(`regionalSeo.countries.${countryCode}.in`) }) }}<ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></LocalizedLink>
    </p>
  </nav>
</template>

<style lang="scss" src="../../scss/components/shared/SeoContent.scss"></style>
