import assert from 'node:assert/strict'
import test from 'node:test'
import { nextTick, reactive } from 'vue'
import { setupView } from './setupView.js'

const items = [
  { id: 1, type: 'image', url: '/images/companies-hero.webp', title: 'One' },
  { id: 2, type: 'image', url: '/images/company-cover.webp', title: 'Two' },
  { id: 3, type: 'image', url: '/images/share.webp', title: 'Three' },
  { id: 4, type: 'video', embedUrl: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', title: 'Four' },
]
async function gallery() {
  const props = reactive({ items: [...items], name: 'Creator' })
  const document = { body: { style: { overflow: 'auto' } } }
  const view = await setupView('../src/components/shared/PortfolioGallery.vue', {}, props, { document })
  return { ...view, props, document }
}

test('all portfolio items, including the fourth video, open in the lightbox and unload on close', async () => {
  const { state, document } = await gallery()
  let opens = 0
  state.dialog.value = { open: false, showModal() { this.open = true; opens += 1 }, close() { this.open = false } }
  assert.equal(state.lightboxItem.value, null)
  state.openLightbox(3)
  await nextTick()
  assert.equal(opens, 1)
  assert.equal(state.lightboxItem.value.id, 4)
  assert.equal(document.body.style.overflow, 'hidden')
  state.moveLightbox(1)
  assert.equal(state.lightboxItem.value.id, 1)
  state.moveLightbox(-1)
  assert.equal(state.lightboxItem.value.id, 4)
  state.closeLightbox()
  assert.equal(state.lightboxItem.value, null)
  assert.equal(document.body.style.overflow, 'auto')
  state.openLightbox(99)
  assert.equal(state.lightboxOpen.value, false)
})

test('portfolio filtering resets the slider and closes the previously selected media', async () => {
  const { state, props } = await gallery()
  const scrolls = []
  state.slider.value = { scrollTo: (options) => scrolls.push(options) }
  state.openLightbox(3)
  await nextTick()
  props.items = [items[0]]
  await nextTick()
  await nextTick()
  assert.equal(state.lightboxItem.value, null)
  assert.equal(state.lightboxIndex.value, 0)
  assert.equal(state.activeSlide.value, 0)
  assert.equal(scrolls.at(-1).left, 0)
})

test('video previews use validated YouTube thumbnails and leave unsupported hosts without a player', async () => {
  const { state } = await gallery()
  assert.equal(state.previewUrl(items[0]), items[0].url)
  assert.equal(state.previewUrl(items[3]), 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
  assert.equal(state.previewUrl({ type: 'video', embedUrl: 'https://www.youtube.com.bad.example/embed/dQw4w9WgXcQ' }), '')
  assert.equal(state.previewUrl({ type: 'video', embedUrl: 'https://player.vimeo.com/video/123' }), '')
})

test('slider navigation respects bounds when several desktop slides are already visible', async () => {
  const { state } = await gallery()
  const element = {
    scrollLeft: 0, scrollWidth: 1230, clientWidth: 1000, clientLeft: 0,
    getBoundingClientRect: () => ({ left: 100 }),
    children: Array.from({ length: 4 }, (_, i) => ({ getBoundingClientRect: () => ({ left: 100 + i * 310 - element.scrollLeft }) })),
    scrollTo({ left }) { element.scrollLeft = Math.max(0, Math.min(230, left)) },
  }
  state.slider.value = element
  state.updateActiveSlide()
  assert.equal(state.atStart.value, true)
  assert.equal(state.atEnd.value, false)
  state.scrollToSlide(3)
  state.updateActiveSlide()
  assert.equal(state.atStart.value, false)
  assert.equal(state.atEnd.value, true)
  state.scrollToSlide(-1)
  state.updateActiveSlide()
  assert.equal(state.atStart.value, true)
})
