<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, CirclePlay, Image, X } from '@lucide/vue'
import CardImage from './CardImage.vue'

const props = defineProps({ items: { type: Array, default: () => [] }, name: { type: String, required: true } })
const { t } = useI18n()
const slider = ref(null)
const dialog = ref(null)
const activeSlide = ref(0)
const atStart = ref(true)
const atEnd = ref(false)
const lightboxIndex = ref(0)
const lightboxOpen = ref(false)
const lightboxItem = computed(() => lightboxOpen.value ? props.items[lightboxIndex.value] || null : null)
let previousOverflow = ''
let disposed = false

function previewUrl(item) {
  if (item.type === 'image') return item.url || ''
  try {
    const url = new URL(item.embedUrl)
    const id = url.pathname.match(/^\/embed\/([A-Za-z0-9_-]{11})$/)?.[1]
    if (id && ['www.youtube-nocookie.com', 'www.youtube.com'].includes(url.hostname)) return `https://i.ytimg.com/vi/${id}/hqdefault.jpg`
  } catch { /* An unavailable video preview still has a play button. */ }
  return ''
}
function updateActiveSlide() {
  const gallery = slider.value
  if (!gallery || !gallery.children.length) return
  atStart.value = gallery.scrollLeft <= 1
  atEnd.value = gallery.scrollLeft >= gallery.scrollWidth - gallery.clientWidth - 1
  const left = gallery.getBoundingClientRect().left + gallery.clientLeft
  const slides = [...gallery.children]
  activeSlide.value = slides.reduce((nearest, slide, index) => Math.abs(slide.getBoundingClientRect().left - left) < Math.abs(slides[nearest].getBoundingClientRect().left - left) ? index : nearest, 0)
}
function scrollToSlide(index) {
  const gallery = slider.value
  const targetIndex = Math.max(0, Math.min(props.items.length - 1, index))
  const slide = gallery?.children[targetIndex]
  if (!slide) return
  activeSlide.value = targetIndex
  gallery.scrollTo({ left: gallery.scrollLeft + slide.getBoundingClientRect().left - gallery.getBoundingClientRect().left - gallery.clientLeft, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
}
function openLightbox(index) {
  if (!Number.isInteger(index) || !props.items[index]) return
  lightboxIndex.value = index
  lightboxOpen.value = true
  nextTick(() => { if (!disposed && lightboxOpen.value && !dialog.value?.open) dialog.value?.showModal() })
}
function closeLightbox() {
  dialog.value?.close()
  lightboxOpen.value = false
}
function moveLightbox(direction) {
  if (props.items.length) lightboxIndex.value = (lightboxIndex.value + direction + props.items.length) % props.items.length
}
watch(lightboxOpen, (open) => {
  if (open) { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden' }
  else document.body.style.overflow = previousOverflow
}, { flush: 'sync' })
watch(() => props.items, () => {
  closeLightbox()
  activeSlide.value = 0
  lightboxIndex.value = 0
  nextTick(() => slider.value?.scrollTo({ left: 0, behavior: 'auto' }))
})
onMounted(() => { updateActiveSlide(); window.addEventListener('resize', updateActiveSlide) })
onBeforeUnmount(() => { disposed = true; window.removeEventListener('resize', updateActiveSlide); if (lightboxOpen.value) document.body.style.overflow = previousOverflow })
</script>

<template>
  <section class="portfolio-gallery" :aria-label="t('creatorProfile.portfolio')">
    <div ref="slider" class="portfolio-gallery__slider" role="region" :aria-label="t('creatorProfile.portfolio')" :aria-description="t('creatorProfile.galleryHint')" tabindex="0" @scroll.passive="updateActiveSlide" @keydown.left.prevent="scrollToSlide(activeSlide - 1)" @keydown.right.prevent="scrollToSlide(activeSlide + 1)">
      <button v-for="(item, index) in items" :key="item.id" class="portfolio-gallery__item" type="button" :aria-label="t('creatorProfile.openPortfolioItem', { title: item.title || name, index: index + 1 })" @click="openLightbox(index)">
        <CardImage :src="previewUrl(item)" :alt="item.title || name" sizes="(max-width: 760px) 80vw, (max-width: 1224px) 25vw, 250px" />
        <span class="portfolio-gallery__type" aria-hidden="true"><CirclePlay v-if="item.type !== 'image'" :size="23" /><Image v-else :size="19" /></span>
      </button>
    </div>
    <div v-if="items.length > 1" class="portfolio-gallery__controls">
      <button type="button" :aria-label="t('creatorProfile.previousItem')" :disabled="atStart" @click="scrollToSlide(activeSlide - 1)"><ChevronLeft :size="19" /></button>
      <span aria-live="polite">{{ activeSlide + 1 }} / {{ items.length }}</span>
      <button type="button" :aria-label="t('creatorProfile.nextItem')" :disabled="atEnd" @click="scrollToSlide(activeSlide + 1)"><ChevronRight :size="19" /></button>
    </div>
    <dialog ref="dialog" class="portfolio-lightbox" :aria-label="t('creatorProfile.portfolio')" @click.self="closeLightbox" @close="lightboxOpen = false" @keydown.left.prevent="moveLightbox(-1)" @keydown.right.prevent="moveLightbox(1)">
      <div v-if="lightboxItem" class="portfolio-lightbox__content">
        <button class="portfolio-lightbox__close" type="button" :aria-label="t('creatorProfile.closeGallery')" autofocus @click="closeLightbox"><X :size="22" /></button>
        <button class="portfolio-lightbox__arrow portfolio-lightbox__arrow--previous" type="button" :aria-label="t('creatorProfile.previousItem')" :disabled="items.length < 2" @click="moveLightbox(-1)"><ChevronLeft :size="25" /></button>
        <div class="portfolio-lightbox__media">
          <img v-if="lightboxItem.type === 'image'" :key="lightboxItem.id" :src="lightboxItem.url" :alt="lightboxItem.title || name" />
          <iframe v-else :key="lightboxItem.id" :src="lightboxItem.embedUrl" :title="lightboxItem.title || name" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
        </div>
        <button class="portfolio-lightbox__arrow portfolio-lightbox__arrow--next" type="button" :aria-label="t('creatorProfile.nextItem')" :disabled="items.length < 2" @click="moveLightbox(1)"><ChevronRight :size="25" /></button>
        <div class="portfolio-lightbox__caption"><span>{{ lightboxItem.title || name }}</span><span>{{ lightboxIndex + 1 }} / {{ items.length }}</span></div>
      </div>
    </dialog>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/PortfolioGallery.scss"></style>
