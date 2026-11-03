<script setup>
import SkeletonBlock from '../shared/SkeletonBlock.vue'
</script>

<template>
  <div class="service-skeleton" aria-hidden="true">
    <SkeletonBlock width="72px" />
    <SkeletonBlock shape="title" width="88%" />
    <SkeletonBlock width="60%" />
    <span class="service-skeleton__creator">
      <SkeletonBlock shape="avatar" size="36px" />
      <SkeletonBlock width="55%" />
    </span>
    <span class="service-skeleton__footer">
      <SkeletonBlock width="70px" />
      <SkeletonBlock width="56px" />
    </span>
  </div>
</template>

<style scoped lang="scss">
@use '../../scss/breakpoints';

.service-skeleton {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-height: 230px;
  box-sizing: border-box;
  padding: 18px;
  background: var(--card);
  border: 1px solid rgba(23, 61, 54, .12);
  border-radius: 20px;
  pointer-events: none;

  @include breakpoints.down(760px) { gap: 10px; min-height: 200px; padding: 12px; border-radius: 16px; }
}

.service-skeleton__creator { display: flex; flex: 1; align-items: flex-end; gap: 10px; }
.service-skeleton__creator > :last-child { margin-bottom: 12px; }

.service-skeleton__footer {
  display: flex;
  justify-content: space-between;
  padding-top: 12px;
  border-top: 1px solid rgba(23, 61, 54, .1);
}
</style>
