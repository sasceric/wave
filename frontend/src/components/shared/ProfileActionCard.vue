<script setup>
import { Info } from '@lucide/vue'
defineProps({
  priceLabel: { type: String, default: '' },
  price: { type: String, default: '' },
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  note: { type: String, default: '' },
  label: { type: String, required: true },
  // The price row can be kept for the phone bar only.
  priceInCard: { type: Boolean, default: true },
})
</script>
<template>
  <section class="profile-action-card" :aria-label="label">
    <div v-if="title || description" class="profile-action-card__copy">
      <strong v-if="title">{{ title }}</strong>
      <p v-if="description">{{ description }}</p>
    </div>
    <div v-if="price && priceInCard" class="profile-action-card__price">
      <small>{{ priceLabel }}</small>
      <strong>{{ price }}</strong>
    </div>
    <div class="profile-action-card__actions">
      <slot />
    </div>
    <p v-if="note" class="profile-action-card__note">
      <Info :size="15" aria-hidden="true" />
      <span>{{ note }}</span>
    </p>
  </section>
  <div class="profile-action-bar" :aria-label="label" role="group">
    <div v-if="price" class="profile-action-bar__price">
      <small>{{ priceLabel }}</small>
      <strong>{{ price }}</strong>
    </div>
    <strong v-else-if="title" class="profile-action-bar__title">{{ title }}</strong>
    <div class="profile-action-bar__actions">
      <slot name="bar" />
    </div>
  </div>
</template>
<style lang="scss" src="../../scss/components/shared/ProfileActionCard.scss"></style>
