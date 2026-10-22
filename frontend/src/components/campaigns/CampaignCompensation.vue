<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { campaignCompensationLabel } from '../../lib/campaignCompensation'

const props = defineProps({ campaign: { type: Object, required: true }, amount: { type: Number, default: null } })
const { t } = useI18n()
const label = computed(() => campaignCompensationLabel(props.campaign, t, props.amount))
</script>

<template><span>{{ label }}</span></template>
