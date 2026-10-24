<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from './LocalizedLink.vue'

// Photo banners: side by side on wider screens, an auto-advancing loop on phones.
const props = defineProps({
  slides: { type: Array, required: true },
  label: { type: String, required: true },
  interval: { type: Number, default: 3000 },
})
const { t } = useI18n()
const track = ref(null)
const active = ref(0)
let timer = 0
let resumeTimer = 0
let paused = false
let phoneQuery
let motionQuery

function slideElements() {
  return Array.from(track.value?.children || [])
}
function goTo(index, behavior = 'smooth') {
  const slides = slideElements()
  if (!slides.length || !track.value) return
  active.value = (index + slides.length) % slides.length
  track.value.scrollTo({ left: slides[active.value].offsetLeft - slides[0].offsetLeft, behavior })
}
function syncActive() {
  const slides = slideElements()
  if (!slides.length || !track.value) return
  const left = track.value.scrollLeft
  let nearest = 0
  slides.forEach((slide, index) => {
    if (Math.abs(slide.offsetLeft - slides[0].offsetLeft - left) < Math.abs(slides[nearest].offsetLeft - slides[0].offsetLeft - left)) nearest = index
  })
  active.value = nearest
}
function stop() {
  window.clearInterval(timer)
  timer = 0
}
function start() {
  stop()
  if (paused || props.slides.length < 2 || !phoneQuery?.matches || motionQuery?.matches || document.visibilityState === 'hidden') return
  timer = window.setInterval(() => goTo(active.value + 1), props.interval)
}
// A touch or focus pauses the loop; it picks up again a moment after the person lets go.
function pause() {
  paused = true
  window.clearTimeout(resumeTimer)
  stop()
}
function resume() {
  window.clearTimeout(resumeTimer)
  resumeTimer = window.setTimeout(() => { paused = false; start() }, props.interval)
}
function choose(index) {
  pause()
  goTo(index)
  resume()
}

onMounted(() => {
  phoneQuery = window.matchMedia('(max-width: 760px)')
  motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  phoneQuery.addEventListener('change', start)
  motionQuery.addEventListener('change', start)
  document.addEventListener('visibilitychange', start)
  start()
})
onBeforeUnmount(() => {
  stop()
  window.clearTimeout(resumeTimer)
  phoneQuery?.removeEventListener('change', start)
  motionQuery?.removeEventListener('change', start)
  document.removeEventListener('visibilitychange', start)
})
</script>

<template>
  <section class="promo-slider" :aria-label="label" aria-roledescription="carousel">
    <div
      ref="track"
      class="promo-slider__track"
      @scroll.passive="syncActive"
      @pointerdown="pause"
      @pointerup="resume"
      @pointercancel="resume"
      @mouseenter="pause"
      @mouseleave="resume"
      @focusin="pause"
      @focusout="resume"
    >
      <LocalizedLink
        v-for="(slide, index) in slides"
        :key="slide.key"
        class="promo-slider__slide"
        :to="slide.to"
        role="group"
        aria-roledescription="slide"
        :aria-label="t('home.promoSlide', { index: index + 1, count: slides.length })"
      >
        <img
          :src="slide.image.src"
          :srcset="slide.image.srcset"
          :sizes="slide.image.sizes"
          :width="slide.image.width"
          :height="slide.image.height"
          :style="{ objectPosition: slide.image.position || 'center' }"
          alt=""
          :loading="index === 0 ? 'eager' : 'lazy'"
          decoding="async"
        />
        <span class="promo-slider__copy">
          <strong>{{ slide.title }}</strong>
          <span>{{ slide.text }}</span>
        </span>
        <span class="promo-slider__arrow" aria-hidden="true"><ArrowRight :size="20" /></span>
      </LocalizedLink>
    </div>
    <div v-if="slides.length > 1" class="promo-slider__dots">
      <button
        v-for="(slide, index) in slides"
        :key="slide.key"
        type="button"
        :class="{ 'is-active': active === index }"
        :aria-label="t('home.promoSlide', { index: index + 1, count: slides.length })"
        :aria-current="active === index ? 'true' : undefined"
        @click="choose(index)"
      />
    </div>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/PromoSlider.scss"></style>
