<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import CreatorsView from './CreatorsView.vue'
import NotFoundView from './NotFoundView.vue'
import { countryCodeForSlug, topicForSlug } from '../lib/regionalSeo'

const route = useRoute()
const countryCode = computed(() => countryCodeForSlug(route.params.country))
// country-topic: an area, UGC creators or a city below the country page.
const topic = computed(() => route.meta.routeName === 'country-topic' ? topicForSlug(countryCode.value, route.params.topic) : null)
const valid = computed(() => Boolean(countryCode.value) && (route.meta.routeName !== 'country-topic' || Boolean(topic.value)))
</script>

<template>
  <CreatorsView v-if="valid" :key="`${countryCode}:${topic?.slug || ''}`" :country-code="countryCode" :topic="topic" />
  <NotFoundView v-else />
</template>
