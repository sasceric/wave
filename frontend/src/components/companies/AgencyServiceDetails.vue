<script setup>
import { Check } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import SocialPlatformIcon from '../shared/SocialPlatformIcon.vue'
defineProps({ service: { type: Object, required: true }, description: { type: Boolean, default: true } })
const { t } = useI18n()
</script>
<template>
  <div class="agency-service-card__body">
    <p v-if="description && service.description" class="agency-service-card__description">{{ service.description }}</p>
    <ul v-if="service.deliverables?.length"><li v-for="(item, index) in service.deliverables" :key="index"><Check :size="16" aria-hidden="true" />{{ item }}</li></ul>
    <div v-if="service.platforms?.length" class="agency-service-card__platforms"><span v-for="platform in service.platforms" :key="platform"><SocialPlatformIcon :platform="platform" :size="14" />{{ platform }}</span></div>
    <p class="agency-service-card__engagement">{{ t(`agency.engagement_${service.engagement || 'both'}`) }}</p>
  </div>
</template>
