<script setup>
import { currentUser } from '../../composables/useCurrentUser'
import { useI18n } from 'vue-i18n'
import { ArrowRight } from '@lucide/vue'
import CreatorWorks from '../creators/CreatorWorks.vue'
import CreatorPackageFacts from '../creators/CreatorPackageFacts.vue'
import { formatDate, formatMoney } from '../../lib/api'
defineProps({ company: { type: Object, required: true }, tab: { type: String, default: 'about' } })
const emit = defineEmits(['request'])
const { t } = useI18n()
</script>
<template>
  <section v-if="tab === 'about' && (company.services.length || company.faqs.length || company.presentation?.languages?.length || company.presentation?.introVideoUrl || company.presentation?.availability && company.presentation.availability !== 'unspecified')" class="company-profile__card agency-services">
    <h2>{{ t('agency.services') }}</h2>
    <div class="company-profile__tags"><span v-for="service in company.services" :key="service">{{ service }}</span></div>
    <p v-if="company.presentation?.introVideoUrl || company.presentation?.availability && company.presentation.availability !== 'unspecified'">{{ t(`agency.${company.presentation.availability}`) }}<template v-if="company.presentation.availability === 'from'"> · {{ formatDate(company.presentation.availableFrom) }}</template></p>
    <p v-if="company.presentation?.languages?.length">{{ company.presentation.languages.map(value => t(`studio.contentLanguages.${value}`)).join(', ') }}</p>
    <p v-if="company.presentation?.introVideoUrl"><a class="text-link" :href="company.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.introVideo') }}<ArrowRight :size="16" /></a></p>
    <details v-for="(faq, index) in company.faqs" :key="index" class="agency-services__faq"><summary>{{ faq.question }}</summary><p>{{ faq.answer }}</p></details>
  </section>
  <section v-else-if="tab === 'portfolio'" class="agency-services">
    <CreatorWorks v-if="company.portfolio.length" :items="company.portfolio" :name="company.name" />
    <p v-else>{{ t('account.notProvided') }}</p>
  </section>
  <section v-else-if="tab === 'packages'" class="agency-packages">
    <article v-for="item in company.packages" :key="item.id" class="company-profile__card agency-package">
      <h2>{{ item.title }}</h2><p>{{ item.description }}</p>
      <strong>{{ item.priceMode === 'agreement' ? t('studio.agreement') : formatMoney(item.price, item.currency) }}</strong>
      <CreatorPackageFacts :item="item" agency />
      <button v-if="currentUser?.id !== company.ownerId" class="button button--dark" type="button" @click="emit('request', item.id)">{{ t('creatorProfile.sendRequest') }}<ArrowRight :size="17" /></button>
    </article>
    <p v-if="!company.packages.length">{{ t('account.notProvided') }}</p>
  </section>
</template>
