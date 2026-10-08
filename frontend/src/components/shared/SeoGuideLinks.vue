<script setup>
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'
import LocalizedLink from './LocalizedLink.vue'
import { ArrowRight, FileText } from '@lucide/vue'
import { guideSlugs, creatorGuideSlugs } from '../../lib/regionalSeo'
const props = defineProps({ forCreators: { type: Boolean, default: false }, panel: { type: Boolean, default: false } })
const titleKey = computed(() => props.forCreators ? 'regionalSeo.creatorGuides.title' : 'regionalSeo.guideTitle')
const copyKey = computed(() => props.forCreators ? 'regionalSeo.creatorGuides.guides' : 'regionalSeo.guides')
const slugs = computed(() => props.forCreators ? creatorGuideSlugs : guideSlugs)
const { t } = useI18n()
</script>

<template>
  <nav class="seo-guide-links" :class="{ 'seo-guide-links--panel': panel }" :aria-label="t(titleKey)">
    <h2>{{ t(titleKey) }}</h2>
    <p v-if="panel">{{ t(forCreators ? 'regionalSeo.creatorGuides.relatedIntro' : 'regionalSeo.guideRelatedIntro') }}</p>
    <ul>
      <li v-for="slug in slugs" :key="slug">
        <LocalizedLink :to="{ name: forCreators ? 'creator-guide' : 'seo-guide', params: { guide: slug } }"><FileText v-if="panel" :size="18" aria-hidden="true" /><span>{{ t(`${copyKey}.${slug}.title`) }}</span><ArrowRight v-if="panel" :size="17" aria-hidden="true" /><span v-else aria-hidden="true">→</span></LocalizedLink>
      </li>
    </ul>
  </nav>
</template>

<style lang="scss" src="../../scss/components/shared/SeoContent.scss"></style>
