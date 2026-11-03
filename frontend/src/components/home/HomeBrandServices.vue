<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CardRow from '../shared/CardRow.vue'
import ServicePackageCard from '../services/ServicePackageCard.vue'
import ServiceTypeChips from '../services/ServiceTypeChips.vue'
import ServicePackageSheet from '../services/ServicePackageSheet.vue'
import ServicePackageSkeleton from '../services/ServicePackageSkeleton.vue'
import { serviceTypeLocation } from '../../lib/serviceTypes'

// "Usluge kreatora": a homepage rail previewing /usluge, filtered in place by type.
const props = defineProps({
  packages: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})
const { t } = useI18n()
const type = ref('')
const selected = ref(null)
const options = computed(() => ['', ...['ugc', 'instagram', 'tiktok', 'photo'].filter(value => props.packages.some(item => item.serviceType === value))])
const visible = computed(() => props.packages.filter(item => !type.value || item.serviceType === type.value))
</script>

<template>
  <CardRow
    kind="creator"
    :title="t('services.homeTitle')"
    :description="t('services.homeText')"
    :link-to="serviceTypeLocation(type)"
    :link-label="t('services.all')"
    :link-short-label="t('home.allCampaignsShort')"
    :busy="loading"
  >
    <template #status>
      <ServiceTypeChips v-if="options.length > 2" v-model="type" class="home-services__chips" :options="options" />
    </template>
    <ServicePackageCard v-for="item in visible" :key="`${item.creator.slug}-${item.id}`" :item="item" @open="selected = $event" />
    <ServicePackageSkeleton v-for="index in loading && !packages.length ? 4 : 0" :key="`loading-${index}`" />
    <template #after><ServicePackageSheet :item="selected" @close="selected = null" /></template>
  </CardRow>
</template>

<style scoped>
.home-services__chips { margin-bottom: 16px; }
</style>
