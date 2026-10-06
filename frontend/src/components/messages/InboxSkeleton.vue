<script setup>
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../shared/LoadingSkeleton.vue'
import SkeletonBlock from '../shared/SkeletonBlock.vue'

defineProps({ openChat: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <div class="campaign-messages__layout" :class="{ 'has-active-chat': openChat }" :aria-label="t('campaignChat.loading')" aria-busy="true">
    <aside class="campaign-messages__inbox">
      <header class="campaign-messages__inbox-heading">
        <h1>{{ t('campaignChat.title') }}</h1>
      </header>
      <SkeletonBlock shape="control" />
      <div class="inbox-skeleton__filters">
        <SkeletonBlock width="30%" />
        <SkeletonBlock width="25%" />
      </div>
      <div class="campaign-messages__conversation-list">
        <LoadingSkeleton variant="rows" :count="6" :label="t('campaignChat.loading')" />
      </div>
    </aside>
    <section class="campaign-messages__thread">
      <header class="campaign-messages__thread-heading">
        <SkeletonBlock shape="avatar" />
        <SkeletonBlock width="45%" />
      </header>
      <div class="campaign-messages__timeline">
        <LoadingSkeleton variant="messages" :label="t('campaignChat.loadingMessages')" />
      </div>
      <div class="inbox-skeleton__composer" aria-hidden="true">
        <SkeletonBlock shape="control" />
        <SkeletonBlock shape="control" width="44px" />
      </div>
    </section>
    <aside class="campaign-messages__details campaign-messages__details--empty" aria-hidden="true">
      <SkeletonBlock shape="media" />
      <SkeletonBlock width="70%" />
      <SkeletonBlock width="90%" />
    </aside>
  </div>
</template>

<style scoped lang="scss" src="./InboxSkeleton.scss"></style>
