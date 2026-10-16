<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({ range: { type: String, default: null } })
const { t } = useI18n()
const label = computed(() => ['fewerThan5', 'upTo10', '10to20', '20to50', '50plus'].includes(props.range)
  ? t(`campaignApplications.ranges.${props.range}`) : '')
</script>

<template>
  <span v-if="label" class="campaign-application-range">{{ t('campaignApplications.label') }}: {{ label }}</span>
</template>
