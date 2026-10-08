<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'
import { apiGet } from '../../lib/api'
import { countries } from '../../lib/regionalSeo'
import { localizedPath } from '../../routePaths'

const props = defineProps({
  availableCountries: { type: Array, default: null },
  compact: { type: Boolean, default: false },
})
const { t, locale } = useI18n()
const fetchedCountries = ref([])
const available = computed(() => (props.availableCountries ?? fetchedCountries.value).filter(item => countries[item.value] && item.count > 0))
onMounted(async () => {
  if (props.availableCountries !== null) return
  try {
    const response = await apiGet('/creators/filters', { locale: locale.value })
    fetchedCountries.value = response.data.countries
  } catch {
    // Optional discovery links should not replace the page with an error.
    fetchedCountries.value = []
  }
})
</script>

<template>
  <nav v-if="available.length" class="seo-country-links" :class="{ 'seo-country-links--compact': compact }" :aria-label="t('regionalSeo.countriesTitle')">
    <h2 v-if="!compact">{{ t('regionalSeo.countriesTitle') }}</h2>
    <div>
      <LocalizedLink v-for="item in available" :key="item.value" :to="localizedPath('country-creators', countries[item.value].locale, { country: countries[item.value].slug })">
        {{ t(`regionalSeo.countries.${item.value}.name`) }} <span aria-hidden="true">→</span>
      </LocalizedLink>
    </div>
  </nav>
</template>

<style lang="scss" src="../../scss/components/shared/SeoContent.scss"></style>
