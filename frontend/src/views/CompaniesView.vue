<script setup>
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CompanyCard from '../components/companies/CompanyCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { apiGet } from '../lib/api'

const companies = ref([])
const error = ref('')
const loading = ref(true)
const { locale, t } = useI18n()

async function loadCompanies() {
  loading.value = true
  error.value = ''
  try {
    const response = await apiGet('/companies')
    companies.value = response.data
  } catch (cause) {
    error.value = cause.message
  } finally {
    loading.value = false
  }
}

watch(locale, loadCompanies)
onMounted(loadCompanies)
</script>

<template>
  <section class="page-width company-directory">
    <div class="company-directory__intro">
      <p class="eyebrow">{{ t('companyDirectory.eyebrow') }}</p>
      <h1>{{ t('companyDirectory.title') }}</h1>
      <p>{{ t('companyDirectory.description') }}</p>
    </div>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="loading">{{ t('companyDirectory.loading') }}</StatusMessage>
    <StatusMessage v-else-if="!companies.length" variant="empty">
      {{ t('companyDirectory.empty') }}
    </StatusMessage>
    <div v-else class="company-directory__grid">
      <CompanyCard v-for="company in companies" :key="company.id" :company="company" />
    </div>
  </section>
</template>
