<script setup>
import { onBeforeUnmount, ref, useId, watch } from 'vue'
import { ArrowRight, ChevronLeft, ChevronRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import CardGrid from './CardGrid.vue'
import LocalizedLink from './LocalizedLink.vue'

// A titled, horizontally scrolling row of cards with previous/next controls.
defineProps({
  kind: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  linkTo: { type: [String, Object], default: '' },
  linkLabel: { type: String, default: '' },
  // Shorter label used on phones so the link fits beside the title.
  linkShortLabel: { type: String, default: '' },
  busy: { type: Boolean, default: false },
  showGrid: { type: Boolean, default: true },
})
const { t } = useI18n()
const titleId = `${useId()}-title`
const grid = ref(null)
const canPrevious = ref(false)
const canNext = ref(false)
let rail = null
let resizeObserver = null
let mutationObserver = null

function update() {
  if (!rail) {
    canPrevious.value = false
    canNext.value = false
    return
  }
  const max = rail.scrollWidth - rail.clientWidth - 2
  canPrevious.value = rail.scrollLeft > 2
  canNext.value = rail.scrollLeft < max
}

function detach() {
  rail?.removeEventListener('scroll', update)
  resizeObserver?.disconnect()
  mutationObserver?.disconnect()
  rail = null
}

function attach(element) {
  detach()
  if (!element) return update()
  rail = element
  rail.addEventListener('scroll', update, { passive: true })
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(update)
    resizeObserver.observe(rail)
  }
  if (typeof MutationObserver !== 'undefined') {
    mutationObserver = new MutationObserver(update)
    mutationObserver.observe(rail, { childList: true })
  }
  update()
}

function scrollRail(direction) {
  if (!rail) return
  const card = rail.firstElementChild
  const gap = parseFloat(getComputedStyle(rail).columnGap) || 0
  rail.scrollBy({ left: direction * ((card?.offsetWidth || rail.clientWidth) + gap), behavior: 'smooth' })
}

watch(grid, component => attach(component?.$el ?? null), { flush: 'post' })
onBeforeUnmount(detach)
</script>

<template>
  <section class="card-row" :aria-labelledby="titleId">
    <div class="card-row__head">
      <div class="card-row__copy">
        <h2 :id="titleId">{{ title }}</h2>
        <p v-if="description">{{ description }}</p>
      </div>
      <div class="card-row__actions">
        <LocalizedLink v-if="linkTo && linkLabel" class="card-row__link" :to="linkTo">
          <span :class="{ 'card-row__link-long': linkShortLabel }">{{ linkLabel }}</span>
          <span v-if="linkShortLabel" class="card-row__link-short" aria-hidden="true">{{ linkShortLabel }}</span>
          <ArrowRight :size="16" aria-hidden="true" />
        </LocalizedLink>
        <template v-if="canPrevious || canNext">
          <button class="card-row__control" type="button" :disabled="!canPrevious" :aria-label="t('home.previousCards')" @click="scrollRail(-1)">
            <ChevronLeft :size="18" aria-hidden="true" />
          </button>
          <button class="card-row__control" type="button" :disabled="!canNext" :aria-label="t('home.nextCards')" @click="scrollRail(1)">
            <ChevronRight :size="18" aria-hidden="true" />
          </button>
        </template>
      </div>
    </div>
    <slot name="status" />
    <CardGrid v-if="showGrid" ref="grid" :kind="kind" layout="home" :aria-busy="busy">
      <slot />
    </CardGrid>
    <slot name="after" />
  </section>
</template>

<style scoped lang="scss" src="../../scss/components/shared/CardRow.scss"></style>
