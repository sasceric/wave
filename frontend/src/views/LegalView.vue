<script setup>
import legalDocuments from '../../../config/legal_documents.json'
import { formatDate } from '../lib/api'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { ArrowRight } from '@lucide/vue'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const { t, tm, rt } = useI18n()

const documentKey = computed(() => ({
  imprint: 'imprint',
  'privacy-policy': 'privacy',
  'cookie-policy': 'cookies',
  'terms-of-use': 'terms',
})[route.meta.routeName] || 'imprint')

const documentContent = computed(() => ({
  title: t(`legal.${documentKey.value}.title`),
  intro: t(`legal.${documentKey.value}.intro`),
  sections: tm(`legal.${documentKey.value}.sections`),
}))

function openCookieSettings() {
  window.dispatchEvent(new Event('wave:open-cookie-settings'))
}
</script>

<template>
  <article class="legal-page page-width">
    <header class="legal-page__header">
      <p class="eyebrow">{{ t('legal.eyebrow') }}</p>
      <h1>{{ documentContent.title }}</h1>
      <p class="legal-page__intro">{{ documentContent.intro }}</p>
      <p class="legal-page__version">{{ t('legal.lastUpdated', { date: formatDate(legalDocuments.effectiveDate) }) }} · {{ legalDocuments.version }}</p>
      <nav class="legal-page__navigation" :aria-label="t('app.footerLegal')">
        <LocalizedLink v-for="[name, key] in [['terms-of-use', 'terms'], ['privacy-policy', 'privacy'], ['cookie-policy', 'cookies'], ['imprint', 'imprint']]" :key="name" :to="{ name }" :aria-current="route.meta.routeName === name ? 'page' : undefined">{{ t(`legal.${key}.title`) }}</LocalizedLink>
      </nav>
    </header>

    <div class="legal-page__content">
      <section
        v-for="section in documentContent.sections"
        :key="rt(section.heading)"
        class="legal-page__section"
      >
        <h2>{{ rt(section.heading) }}</h2>
        <p v-for="paragraph in section.paragraphs" :key="rt(paragraph)">
          {{ rt(paragraph) }}
        </p>
        <p v-if="section.links?.length" class="legal-page__links">
          <a
            v-for="link in section.links"
            :key="rt(link.url)"
            :href="rt(link.url)"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ rt(link.label) }}
            <ArrowRight class="link-arrow" :size="16" aria-hidden="true" />
          </a>
        </p>
      </section>

      <button
        v-if="documentKey === 'cookies'"
        class="button button--dark legal-page__settings"
        type="button"
        @click="openCookieSettings"
      >
        {{ t('legal.openCookieSettings') }}
      </button>

      <a class="legal-page__contact" href="mailto:info@wave.ba">
        info@wave.ba
        <ArrowRight class="link-arrow" :size="16" aria-hidden="true" />
      </a>
    </div>
  </article>
</template>

<style lang="scss" src="../scss/views/LegalView.scss"></style>
