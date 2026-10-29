<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import { apiGet } from '../../lib/api'
import { countries, topicCopy, topicForSlug } from '../../lib/regionalSeo'
import { localizedPath } from '../../routePaths'

// Footer "Explore": the visitor's country page and its most populated landing pages.
const COUNTRY_BY_LOCALE = { bs: 'BA', hr: 'HR', sr: 'RS', cnr: 'ME', sl: 'SI', en: 'BA', de: 'BA' }
const { t, locale } = useI18n()
const items = ref([])
const country = computed(() => COUNTRY_BY_LOCALE[locale.value] || 'BA')
const links = computed(() => {
  const code = country.value
  if (!items.value.length) return []
  const pages = items.value.slice(0, 3).map((item) => {
    const topic = topicForSlug(code, item.slug)
    return topic && { key: item.slug, label: topicCopy(t, locale.value, code, topic).title, to: localizedPath('country-topic', locale.value, { country: countries[code].slug, topic: item.slug }) }
  }).filter(Boolean)
  return [{ key: 'country', label: t(`regionalSeo.countries.${code}.title`), to: localizedPath('country-creators', locale.value, { country: countries[code].slug }) }, ...pages]
})

async function load() {
  const code = country.value
  try {
    const response = await apiGet(`/creators/landing-pages?country=${code}`, { locale: locale.value })
    if (code === country.value) items.value = Array.isArray(response.data) ? response.data : []
  } catch {
    items.value = []
  }
}
onMounted(load)
watch(country, load)
</script>

<template>
  <LocalizedLink v-for="link in links" :key="link.key" :to="link.to">{{ link.label }}</LocalizedLink>
</template>
