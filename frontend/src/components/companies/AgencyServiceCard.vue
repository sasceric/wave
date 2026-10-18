<script setup>
import { Briefcase, ChevronDown } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AgencyServiceDetails from './AgencyServiceDetails.vue'
defineProps({ service: { type: Object, required: true }, collapsible: Boolean, initiallyOpen: Boolean })
const { t } = useI18n()
</script>
<template>
  <component :is="collapsible ? 'details' : 'article'" class="agency-service-card" :class="{ 'agency-service-card--collapsible': collapsible }" :open="collapsible && initiallyOpen || undefined">
    <component :is="collapsible ? 'summary' : 'header'">
      <div class="agency-service-card__identity"><h3><Briefcase :size="18" aria-hidden="true" />{{ service.title || t('agency.serviceTitle') }}</h3><p v-if="collapsible && service.description" class="agency-service-card__description">{{ service.description }}</p></div>
      <ChevronDown v-if="collapsible" class="agency-service-card__chevron" :size="16" aria-hidden="true" /><slot v-else name="actions" />
    </component>
    <slot>
      <AgencyServiceDetails :service="service" :description="!collapsible" />
    </slot>
  </component>
</template>
<style lang="scss" src="../../scss/components/companies/AgencyServiceCard.scss"></style>
