<script setup>
import { Check, Briefcase } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
defineProps({ service: { type: Object, required: true } })
const { t } = useI18n()
</script>
<template>
  <article class="agency-service-card">
    <header><h3><Briefcase :size="18" aria-hidden="true" />{{ service.title || t('agency.serviceTitle') }}</h3><slot name="actions" /></header>
    <slot>
      <p v-if="service.description" class="agency-service-card__description">{{ service.description }}</p>
      <ul v-if="service.deliverables?.length"><li v-for="(item, index) in service.deliverables" :key="index"><Check :size="16" aria-hidden="true" />{{ item }}</li></ul>
      <div v-if="service.platforms?.length" class="agency-service-card__platforms"><span v-for="platform in service.platforms" :key="platform">{{ platform }}</span></div>
      <p class="agency-service-card__engagement">{{ t(`agency.engagement_${service.engagement || 'both'}`) }}</p>
    </slot>
  </article>
</template>
<style lang="scss" src="../../scss/components/companies/AgencyServiceCard.scss"></style>
