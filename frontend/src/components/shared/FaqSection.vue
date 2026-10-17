<script setup>
import { ChevronDown } from '@lucide/vue'
import { useId } from 'vue'
const titleId = `${useId()}-faq-title`

defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  items: { type: Array, default: () => [] },
})
</script>

<template>
  <section class="faq-section" :aria-labelledby="titleId">
    <div class="faq-section__intro">
      <p class="eyebrow">{{ eyebrow }}</p>
      <h2 :id="titleId"><slot name="title">{{ title }}</slot></h2>
      <p v-if="description">{{ description }}</p>
    </div>
    <div class="faq-section__list">
      <details v-for="(item, index) in items" :key="item.id" class="faq-section__item">
        <summary>
          <slot name="icon" :item="item" :index="index" />
          <span>{{ item.question }}</span>
          <ChevronDown :size="18" stroke-width="1.7" aria-hidden="true" />
        </summary>
        <div class="faq-section__answer">{{ item.answer }}</div>
      </details>
    </div>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/FaqSection.scss"></style>
