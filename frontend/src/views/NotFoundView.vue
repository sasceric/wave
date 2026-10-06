<script setup>
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <section class="not-found page-width">
    <p class="eyebrow">{{ t('notFound.eyebrow') }}</p>
    <h1>{{ t('notFound.headlineLead') }} <em>{{ t('notFound.headlineEmphasis') }}</em></h1>
    <p>{{ t('notFound.description') }}</p>
    <LocalizedLink class="button button--dark" to="/">{{ t('notFound.backHome') }} <span aria-hidden="true">↗</span></LocalizedLink>
  </section>
</template>

<style lang="scss" src="../scss/views/NotFoundView.scss"></style>
