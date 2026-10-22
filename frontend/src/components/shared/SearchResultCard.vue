<script setup>
import CampaignCompensation from '../campaigns/CampaignCompensation.vue'
import { computed } from 'vue'
import { MapPin, Coins } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import CardImage from './CardImage.vue'
import LocalizedLink from './LocalizedLink.vue'
import PresentationDemoBadge from './PresentationDemoBadge.vue'
const props = defineProps({ item: { type: Object, required: true } })
const { t } = useI18n()
const route = computed(() => ({ name: { creators: 'creator-profile', campaigns: 'campaign-detail', companies: 'company-profile' }[props.item.kind], params: { slug: props.item.slug } }))
</script>
<template>
  <LocalizedLink class="search-result-card" :to="route" :aria-label="`${t(`globalSearch.open_${item.kind}`)}: ${item.title}`">
    <header><CardImage :image="item.image" :src="item.imageUrl || ''" :alt="item.title" sizes="64px" /><div><small>{{ t(`globalSearch.${item.kind}`) }}</small><h3>{{ item.title }}</h3><p>{{ item.subtitle }}</p></div></header>
    <p class="search-result-card__summary">{{ item.summary }}</p>
    <div class="search-result-card__facts"><span v-if="item.location"><MapPin :size="15" aria-hidden="true" />{{ item.location }}</span><span v-if="item.kind === 'campaigns'"><Coins :size="15" aria-hidden="true" /><CampaignCompensation :campaign="item" /></span></div>
    <PresentationDemoBadge v-if="item.isPresentationDemo" />
  </LocalizedLink>
</template>
<style lang="scss" src="../../scss/components/shared/SearchResultCard.scss"></style>
