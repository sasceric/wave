<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import LocalizedLink from '../shared/LocalizedLink.vue'
import { currentUser } from '../../composables/useCurrentUser'

// One "Collaborations" (creators, service providers) or "Campaigns" (companies) place:
// the former separate pages become tabs that keep their own routes and filters.
const { t } = useI18n()
const route = useRoute()
const creator = computed(() => currentUser.value?.accountType === 'creator')
const companyWork = computed(() => !creator.value && ['applications', 'bookmarks'].includes(route.meta.accountSection))
const title = computed(() => creator.value ? t('accountHub.work') : companyWork.value ? t('agency.providerWork') : t('accountHub.campaigns'))
const intro = computed(() => creator.value ? t('accountHub.workIntro') : companyWork.value ? t('accountHub.providerIntro') : t('accountHub.campaignsIntro'))
const tabs = computed(() => {
  if (creator.value) return [['account-applications', 'tabApplications'], ['account-inquiries', 'tabInquiries'], ['account-bookmarks', 'tabSaved']]
  if (companyWork.value) return [['account-applications', 'tabApplications'], ['account-bookmarks', 'tabSaved']]
  return [['account-campaigns', 'tabCampaigns'], ['account-inquiries', 'tabRequests']]
})
</script>

<template>
  <header class="account-work-tabs">
    <div class="account-work-tabs__copy">
      <p class="account-work-tabs__title">{{ title }}</p>
      <p class="account-work-tabs__intro">{{ intro }}</p>
    </div>
    <nav class="account-work-tabs__nav" :aria-label="title">
      <LocalizedLink
        v-for="[name, key] in tabs"
        :key="name"
        :to="{ name }"
        :class="{ 'is-active': route.meta.routeName === name }"
        :aria-current="route.meta.routeName === name ? 'page' : undefined"
      >{{ t(`accountHub.${key}`) }}</LocalizedLink>
    </nav>
  </header>
</template>

<style lang="scss" src="../../scss/components/account/AccountWorkTabs.scss"></style>
