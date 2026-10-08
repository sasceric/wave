<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import CreatorsView from './CreatorsView.vue'
import NotFoundView from './NotFoundView.vue'
import { countryCodeForSlug } from '../lib/regionalSeo'

const route = useRoute()
const countryCode = computed(() => countryCodeForSlug(route.params.country))
</script>

<template>
  <CreatorsView v-if="countryCode" :key="countryCode" :country-code="countryCode" />
  <NotFoundView v-else />
</template>
