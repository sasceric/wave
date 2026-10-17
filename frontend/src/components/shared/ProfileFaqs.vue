<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import FaqSection from './FaqSection.vue'
const props = defineProps({ items: { type: Array, required: true } })
const { t } = useI18n()
const questions = computed(() => props.items.map((item, index) => ({ ...item, id: item.id || `profile-${index}` })))
</script>
<template><FaqSection class="profile-faqs" :items="questions" :eyebrow="t('creatorProfile.faqEyebrow')" :title="t('creatorProfile.faqTitle')" /></template>
<style lang="scss" src="../../scss/components/shared/ProfileFaqs.scss"></style>
