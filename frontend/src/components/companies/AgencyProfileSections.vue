<script setup>
import { computed } from 'vue'
import { currentUser } from '../../composables/useCurrentUser'
import { useI18n } from 'vue-i18n'
import { ArrowRight } from '@lucide/vue'
import CreatorWorks from '../creators/CreatorWorks.vue'
import ProfilePackages from '../shared/ProfilePackages.vue'
import ProfileFaqs from '../shared/ProfileFaqs.vue'
import AgencyServiceCard from './AgencyServiceCard.vue'
import { formatDate } from '../../lib/api'
const props = defineProps({ company: { type: Object, required: true } })
const emit = defineEmits(['request'])
const { t } = useI18n()
const canRequest = computed(() => currentUser.value?.id !== props.company.ownerId && (!currentUser.value || currentUser.value.accountType === 'company'))
const packages = computed(() => [...props.company.packages, { profileKey: 'negotiation', isNegotiationOption: true, title: t('creatorProfile.negotiatePackage'), description: t('creatorProfile.negotiatePackageDescription') }])
</script>
<template>
  <div class="agency-profile-layout">
  <div class="agency-profile-layout__main">
  <section id="company-services" class="agency-services">
    <h2>{{ t('agency.services') }}</h2>
    <div v-if="company.services.length" class="agency-services__grid"><AgencyServiceCard v-for="service in company.services" :key="service.id" :service="service" /></div>
    <p v-else>{{ t('account.notProvided') }}</p>
    <div class="agency-services__availability">
      <p v-if="company.presentation?.availability && company.presentation.availability !== 'unspecified'">{{ t(`agency.${company.presentation.availability}`) }}<template v-if="company.presentation.availability === 'from'"> · {{ formatDate(company.presentation.availableFrom) }}</template></p>
      <p v-if="company.presentation?.languages?.length">{{ company.presentation.languages.map(value => t(`studio.contentLanguages.${value}`)).join(', ') }}</p>
      <p v-if="company.presentation?.introVideoUrl"><a class="text-link" :href="company.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.introVideo') }}<ArrowRight :size="16" aria-hidden="true" /></a></p>
    </div>
  </section>
  <CreatorWorks v-if="company.portfolio.length" id="company-works" :items="company.portfolio" :name="company.name" />
  </div>
  <aside class="agency-profile-layout__sidebar">
  <ProfilePackages id="company-packages" :items="packages" :title="t('agency.offeringTitle')" :can-request="canRequest" agency @request="emit('request', $event.isNegotiationOption ? null : $event.id)" />
  <ProfileFaqs v-if="company.faqs.length" id="company-faqs" :items="company.faqs" />
  </aside>
  </div>
</template>
