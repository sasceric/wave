<script setup>
import { ChevronDown } from '@lucide/vue'
import { useId } from 'vue'
const titleId = `${useId()}-faq-title`

defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  // split: intro beside the questions on wide screens.
  split: { type: Boolean, default: false },
})
</script>

<template>
  <section class="faq-section" :class="{ 'faq-section--split': split }" :aria-labelledby="titleId">
    <div class="faq-section__intro">
      <p class="eyebrow">{{ eyebrow }}</p>
      <h2 :id="titleId"><slot name="title">{{ title }}</slot></h2>
      <p v-if="description" class="faq-section__description">{{ description }}</p>
      <div v-if="$slots.actions" class="faq-section__actions"><slot name="actions" /></div>
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
