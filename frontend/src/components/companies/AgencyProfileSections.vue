<script setup>
import { computed } from 'vue'
import { currentUser } from '../../composables/useCurrentUser'
import { useI18n } from 'vue-i18n'
import CreatorWorks from '../creators/CreatorWorks.vue'
import ProfilePackages from '../shared/ProfilePackages.vue'
import ProfileFaqs from '../shared/ProfileFaqs.vue'
import AgencyServiceCard from './AgencyServiceCard.vue'
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
        <header class="agency-public-profile__section-heading"><h2>{{ t('agency.publicServicesTitle') }}</h2><p>{{ t('agency.publicServicesHint') }}</p></header>
        <div v-if="company.services.length" class="agency-services__grid"><AgencyServiceCard v-for="service in company.services" :key="service.id" :service="service" /></div>
        <p v-else>{{ t('account.notProvided') }}</p>
      </section>
      <ProfilePackages id="company-packages" cards :description="t('publicProfile.packagesHint')" :items="packages" :title="t('agency.offeringTitle')" :can-request="canRequest" agency @request="emit('request', $event.isNegotiationOption ? null : $event.id)" />
      <CreatorWorks v-if="company.portfolio.length" id="company-works" :items="company.portfolio" :name="company.name" />
      <slot name="campaigns" />
      <slot name="reviews" />
      <ProfileFaqs v-if="company.faqs.length" id="company-faqs" :items="company.faqs" />
      <slot name="about" />
    </div>
    <aside class="agency-profile-layout__sidebar">
      <slot name="aside" />
    </aside>
  </div>
</template>
