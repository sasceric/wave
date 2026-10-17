<script setup>
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { ArrowUpRight } from '@lucide/vue'

const { t } = useI18n()
</script>

<template>
  <main class="not-found page-width">
    <div class="not-found__content">
      <p class="not-found__eyebrow">{{ t('notFound.eyebrow') }}</p>
      <h1><span>{{ t('notFound.headlineLead') }}</span><em>{{ t('notFound.headlineEmphasis') }}</em></h1>
      <p class="not-found__description">{{ t('notFound.description') }}</p>
      <div class="not-found__actions">
        <LocalizedLink class="button button--dark" :to="{ name: 'home' }">{{ t('notFound.backHome') }}<ArrowUpRight :size="24" aria-hidden="true" /></LocalizedLink>
        <LocalizedLink class="button button--outline" :to="{ name: 'creators' }">{{ t('notFound.browseCreators') }}</LocalizedLink>
      </div>
    </div>
    <img class="not-found__art" src="/images/404-illustration.svg" width="640" height="520" alt="" aria-hidden="true" decoding="async" />
  </main>
</template>

<style lang="scss" src="../scss/views/NotFoundView.scss"></style>
