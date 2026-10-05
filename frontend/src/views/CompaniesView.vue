<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CompanyCard from '../components/companies/CompanyCard.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useDirectoryData } from '../composables/useDirectoryData'

const { locale, t } = useI18n()
const page = ref(1)
const pageSize = ref(30)
const { items: companies, total, loading, error } = useDirectoryData(
  '/companies',
  locale,
  page,
  pageSize,
)
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
    <DirectoryPagination
      :page="page"
      :page-size="pageSize"
      :total="total"
      @update:page="page = $event"
      @update:page-size="pageSize = $event"
    />
  </section>
</template>
