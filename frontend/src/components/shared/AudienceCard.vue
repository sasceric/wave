<script setup>
import { CircleCheck } from '@lucide/vue'
import { useId } from 'vue'

// One entry point for an audience (creators, brands): who it is for, three reasons, two actions.
defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  items: { type: Array, default: () => [] },
  icon: { type: [Object, Function], default: null },
  image: { type: Object, default: null },
  dark: { type: Boolean, default: false },
})
const titleId = `${useId()}-title`
</script>

<template>
  <article class="audience-card" :class="{ 'audience-card--dark': dark }" :aria-labelledby="titleId">
    <div class="audience-card__body">
      <p class="audience-card__eyebrow">
        <component :is="icon" v-if="icon" :size="16" aria-hidden="true" />
        {{ eyebrow }}
      </p>
      <h2 :id="titleId">{{ title }}</h2>
      <ul v-if="items.length">
        <li v-for="item in items" :key="item"><CircleCheck :size="17" aria-hidden="true" />{{ item }}</li>
      </ul>
      <div class="audience-card__actions"><slot name="actions" /></div>
    </div>
    <div v-if="image" class="audience-card__media" aria-hidden="true">
      <img
        :src="image.src"
        :srcset="image.srcset"
        :sizes="image.sizes"
        :width="image.width"
        :height="image.height"
        alt=""
        loading="lazy"
        decoding="async"
      />
    </div>
  </article>
</template>

<style scoped lang="scss" src="../../scss/components/shared/AudienceCard.scss"></style>
