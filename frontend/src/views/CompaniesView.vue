<script setup>
import CardGrid from '../components/shared/CardGrid.vue'
import { useI18n } from 'vue-i18n'
import CompanyCard from '../components/companies/CompanyCard.vue'
import DirectoryLoadMore from '../components/shared/DirectoryLoadMore.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { useInfiniteDirectory } from '../composables/useInfiniteDirectory'

const { locale, t } = useI18n()
const { items: companies, loading, error, hasMore, loadMore } = useInfiniteDirectory(
  '/companies',
  locale,
)
</script>

<template>
  <section class="page-width company-directory">
    <div class="company-directory__intro">
      <p class="eyebrow">{{ t('companyDirectory.eyebrow') }}</p>
      <h1>{{ t('companyDirectory.title') }}</h1>
      <p>{{ t('companyDirectory.description') }}</p>
    </div>
    <StatusMessage v-if="!loading && !error && !companies.length" variant="empty">
      {{ t('companyDirectory.empty') }}
    </StatusMessage>
    <CardGrid v-if="companies.length || loading" kind="company" layout="directory" :aria-busy="loading">
      <CompanyCard v-for="company in companies" :key="company.id" :company="company" />
      <DirectorySkeletonCard v-for="index in loading ? (companies.length ? 2 : 6) : 0" :key="`loading-${index}`" kind="company" />
    </CardGrid>
    <DirectoryLoadMore
      :loading="loading"
      :error="error"
      :has-more="hasMore"
      :count="companies.length"
      @load="loadMore"
    />
  </section>
</template>

<style lang="scss" src="../scss/views/CompaniesView.scss"></style>
