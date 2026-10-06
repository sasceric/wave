<script setup>
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
        <div v-else-if="content === 'bookmarks'" class="campaign-grid account-bookmarks-grid">
          <DirectorySkeletonCard v-for="index in 4" :key="index" kind="campaign" />
        </div>
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
      <div class="admin-dashboard__metrics loading-skeleton__metrics">
        <div v-for="index in 4" :key="index" class="admin-dashboard__metric loading-skeleton__panel">
          <SkeletonBlock width="80%" />
          <SkeletonBlock shape="title" width="45%" />
        </div>
      </div>
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
        <div class="campaign-grid campaign-grid--directory">
          <DirectorySkeletonCard v-for="index in 4" :key="index" kind="campaign" />
        </div>
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

<style scoped>
.loading-skeleton { min-width: 0; }
.loading-skeleton__table-row { display: grid; grid-template-columns: repeat(var(--skeleton-columns), minmax(0, 1fr)); gap: 24px; align-items: center; min-height: 48px; padding: 12px 0; border-bottom: 1px solid var(--line); }
.loading-skeleton__copy, .loading-skeleton__panel { display: grid; min-width: 0; gap: 16px; }
.loading-skeleton__row, .loading-skeleton__identity { display: flex; align-items: center; gap: 14px; }
.loading-skeleton__copy { flex: 1; gap: 9px; }
.loading-skeleton__identity { min-height: 95px; margin-bottom: 24px; }
.loading-skeleton__rows { display: grid; gap: 8px; }
.loading-skeleton__row { min-height: 90px; padding: 16px 12px; border-bottom: 1px solid var(--line); }
.loading-skeleton__portfolio { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.loading-skeleton__fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 28px; padding: 20px 0; }
.loading-skeleton__tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 12px; }
.loading-skeleton__metrics { margin: 24px 0; }
.loading-skeleton__messages { display: flex; flex-direction: column; justify-content: flex-end; gap: 24px; min-height: 320px; padding: 8px 0; }
.loading-skeleton__bubble { display: grid; gap: 13px; width: min(70%, 320px); min-height: 76px; padding: 20px; border-radius: 18px 18px 18px 4px; background: #edece3; }
.loading-skeleton__bubble.is-outgoing { align-self: flex-end; border-radius: 18px 18px 4px 18px; background: #dce6df; }
.loading-skeleton__company { display: grid; justify-items: center; gap: 18px; }
@media (max-width: 760px) {
  .loading-skeleton__fields { grid-template-columns: 1fr; }
  .loading-skeleton__tabs { gap: 6px; }
  .loading-skeleton__bubble { width: 75%; }
}
</style>
