<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

const route = useRoute()
const { t, tm, rt } = useI18n()

const documentKey = computed(() => ({
  imprint: 'imprint',
  'privacy-policy': 'privacy',
  'cookie-policy': 'cookies',
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
    </header>

    <div class="legal-page__content">
      <section
        v-for="section in documentContent.sections"
        :key="section.heading"
        class="legal-page__section"
      >
        <h2>{{ rt(section.heading) }}</h2>
        <p v-for="paragraph in section.paragraphs" :key="paragraph">
          {{ rt(paragraph) }}
        </p>
        <p v-if="section.links?.length" class="legal-page__links">
          <a
            v-for="link in section.links"
            :key="link.url"
            :href="link.url"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ rt(link.label) }}
            <span aria-hidden="true">↗</span>
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
        <span aria-hidden="true">↗</span>
      </a>
    </div>
  </article>
</template>

<style lang="scss" src="../scss/views/LegalView.scss"></style>
