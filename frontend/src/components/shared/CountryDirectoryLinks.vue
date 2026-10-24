<script setup>
import { ArrowRight, MapPin } from '@lucide/vue'
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
    <span v-else class="seo-country-links__label">{{ t('regionalSeo.countriesTitle') }}</span>
    <div>
      <LocalizedLink v-for="item in available" :key="item.value" :to="localizedPath('country-creators', countries[item.value].locale, { country: countries[item.value].slug })">
        <MapPin v-if="compact" :size="15" aria-hidden="true" />
        {{ t(`regionalSeo.countries.${item.value}.name`) }}
        <span v-if="compact" class="seo-country-links__count">{{ item.count }}</span>
        <ArrowRight v-else class="link-arrow" :size="16" aria-hidden="true" />
      </LocalizedLink>
    </div>
  </nav>
</template>

<style lang="scss" src="../../scss/components/shared/SeoContent.scss"></style>
