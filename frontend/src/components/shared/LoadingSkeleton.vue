<script setup>
import AdminMetrics from '../admin/AdminMetrics.vue'
import CardGrid from './CardGrid.vue'
import { useI18n } from 'vue-i18n'
import DirectorySkeletonCard from './DirectorySkeletonCard.vue'
import SkeletonBlock from './SkeletonBlock.vue'

defineProps({
  variant: { type: String, default: 'rows' },
  label: { type: String, default: '' },
  count: { type: Number, default: 5 },
  columns: { type: Number, default: 4 },
  content: { type: String, default: 'profile' },
})
const { t } = useI18n()
</script>

<template>
  <div class="loading-skeleton" :class="`loading-skeleton--${variant}`" role="status" aria-busy="true">
    <span class="sr-only">{{ label || t('app.loading') }}</span>
    <div v-if="variant === 'messages'" class="loading-skeleton__messages" aria-hidden="true">
      <div v-for="index in count" :key="index" class="loading-skeleton__bubble" :class="{ 'is-outgoing': index % 2 === 0 }">
        <SkeletonBlock :width="index % 3 === 0 ? '65%' : '90%'" />
        <SkeletonBlock width="45%" />
      </div>
    </div>
    <div v-else-if="variant === 'table'" class="loading-skeleton__table" :style="{ '--skeleton-columns': columns }" aria-hidden="true">
      <div v-for="index in count" :key="index" class="loading-skeleton__table-row">
        <SkeletonBlock v-for="column in columns" :key="column" width="80%" />
      </div>
    </div>
    <div v-else-if="variant === 'rows'" class="loading-skeleton__rows" aria-hidden="true">
      <div v-for="index in count" :key="index" class="loading-skeleton__row">
        <SkeletonBlock shape="avatar" />
        <div class="loading-skeleton__copy">
          <SkeletonBlock width="65%" />
          <SkeletonBlock width="90%" />
          <SkeletonBlock width="40%" />
        </div>
      </div>
    </div>
    <div v-else-if="variant === 'account'" aria-hidden="true">
      <div class="loading-skeleton__identity">
        <SkeletonBlock shape="avatar" size="58px" />
        <div class="loading-skeleton__copy">
          <SkeletonBlock shape="title" width="60%" />
          <SkeletonBlock width="35%" />
        </div>
      </div>
      <div class="form-card loading-skeleton__panel">
        <SkeletonBlock shape="title" width="45%" />
        <SkeletonBlock width="70%" />
        <div v-if="content === 'profile'" class="loading-skeleton__tabs">
          <SkeletonBlock v-for="index in 4" :key="index" shape="control" />
        </div>
        <div v-if="content === 'profile'" class="loading-skeleton__fields">
          <div v-for="index in 8" :key="index" class="loading-skeleton__copy">
            <SkeletonBlock width="40%" />
            <SkeletonBlock shape="control" />
          </div>
        </div>
        <CardGrid v-else-if="content === 'bookmarks'" kind="campaign" layout="bookmarks">
          <DirectorySkeletonCard v-for="index in 4" :key="index" kind="campaign" />
        </CardGrid>
        <div v-else class="loading-skeleton__rows">
          <div v-for="index in count" :key="index" class="loading-skeleton__row">
            <SkeletonBlock shape="avatar" />
            <div class="loading-skeleton__copy">
              <SkeletonBlock width="65%" />
              <SkeletonBlock width="90%" />
              <SkeletonBlock width="40%" />
            </div>
          </div>
        </div>
      </div>
    </div>
    <div v-else-if="variant === 'dashboard'" aria-hidden="true">
      <SkeletonBlock shape="title" width="40%" />
      <AdminMetrics class="admin-dashboard__metrics loading-skeleton__metrics">
        <div v-for="index in 4" :key="index" class="admin-dashboard__metric loading-skeleton__panel">
          <SkeletonBlock width="80%" />
          <SkeletonBlock shape="title" width="45%" />
        </div>
      </AdminMetrics>
      <div class="form-card loading-skeleton__panel">
        <SkeletonBlock v-for="index in 8" :key="index" shape="control" />
      </div>
    </div>
    <div v-else-if="variant === 'company'" aria-hidden="true">
      <div class="company-cover">
        <div class="page-width company-cover__inner loading-skeleton__company">
          <SkeletonBlock shape="avatar" />
          <SkeletonBlock shape="title" width="45%" />
          <SkeletonBlock width="30%" />
          <SkeletonBlock width="65%" />
        </div>
      </div>
      <div class="page-width company-briefs loading-skeleton__panel">
        <SkeletonBlock shape="title" width="40%" />
        <CardGrid kind="campaign" layout="directory">
          <DirectorySkeletonCard v-for="index in 4" :key="index" kind="campaign" />
        </CardGrid>
      </div>
    </div>
    <div v-else-if="variant === 'campaign'" aria-hidden="true">
      <div class="brief-hero">
        <div class="page-width brief-hero__inner loading-skeleton__panel">
          <SkeletonBlock width="20%" />
          <SkeletonBlock shape="title" width="75%" />
          <SkeletonBlock width="65%" />
          <div class="loading-skeleton__identity">
            <SkeletonBlock shape="avatar" />
            <SkeletonBlock width="25%" />
          </div>
        </div>
      </div>
      <div class="page-width brief-layout">
        <div class="loading-skeleton__panel">
          <SkeletonBlock v-for="index in 10" :key="index" :width="index % 3 === 0 ? '65%' : '95%'" />
        </div>
        <div class="form-card loading-skeleton__panel">
          <SkeletonBlock shape="title" width="70%" />
          <SkeletonBlock v-for="index in 4" :key="index" />
          <SkeletonBlock shape="control" />
        </div>
      </div>
    </div>
    <div v-else class="page-width profile-layout" aria-hidden="true">
      <div class="profile-main loading-skeleton__panel">
        <div class="loading-skeleton__identity">
          <SkeletonBlock shape="avatar" size="84px" />
          <SkeletonBlock shape="title" width="60%" />
        </div>
        <div class="loading-skeleton__portfolio">
          <SkeletonBlock v-for="index in 3" :key="index" shape="media" />
        </div>
        <SkeletonBlock v-for="index in 4" :key="index" />
        <div class="form-card loading-skeleton__panel">
          <SkeletonBlock shape="title" width="50%" />
          <SkeletonBlock v-for="index in 4" :key="index" />
        </div>
      </div>
      <div class="profile-aside loading-skeleton__panel">
        <SkeletonBlock shape="title" width="65%" />
        <SkeletonBlock v-for="index in 5" :key="index" />
        <div class="form-card loading-skeleton__panel">
          <SkeletonBlock shape="title" width="60%" />
          <SkeletonBlock v-for="index in 4" :key="index" />
          <SkeletonBlock shape="control" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss" src="../../scss/components/shared/LoadingSkeleton.scss"></style>
