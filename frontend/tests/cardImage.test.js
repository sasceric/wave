import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'
import { listingImage } from '../src/lib/listingImage.js'

async function imageState(props) {
  let mounted
  const result = await setupView('../src/components/shared/CardImage.vue', {
    vue: { ...vue, onMounted: (callback) => { mounted = callback } },
    '../../lib/listingImage': { listingImage },
  }, props)
  return { ...result, mounted: () => mounted() }
}

test('cached images reveal immediately; new image URLs reset their loading state', async () => {
  const props = vue.reactive({ src: '/original.webp', image: { src: '/thumb/320' } })
  const { state, mounted } = await imageState(props)
  assert.equal(state.state.value, 'loading')
  state.element.value = { complete: true, naturalWidth: 320 }
  mounted()
  assert.equal(state.state.value, 'loaded')
  state.element.value = { complete: false, naturalWidth: 0 }
  props.image = { src: '/different-thumb/320' }
  await vue.nextTick()
  await vue.nextTick()
  assert.equal(state.state.value, 'loading')
  state.element.value = { complete: true, naturalWidth: 320 }
  state.checkCachedImage()
  assert.equal(state.state.value, 'loaded')
})

test('cards prefer uploaded thumbnails and optimize only the known external resize provider', () => {
  const uploaded = { src: '/api/media/1/thumbnail/v1/320', srcset: '/api/media/1/thumbnail/v1/480 480w' }
  assert.equal(listingImage(uploaded, '/api/media/1/file'), uploaded)
  const original = 'https://images.unsplash.com/photo-example?w=720&q=85&auto=format'
  const thumbnail = listingImage(null, original)
  assert.equal(new URL(thumbnail.src).searchParams.get('w'), '320')
  assert.equal(new URL(thumbnail.src).searchParams.get('q'), '85')
  assert.ok(thumbnail.srcset.includes('w=480'))
  assert.equal(new URL(original).searchParams.get('w'), '720')
  assert.deepEqual(listingImage(null, '/images/logo.svg'), { src: '/images/logo.svg' })
  assert.deepEqual(listingImage(null, 'https://other.example/image.png'), { src: 'https://other.example/image.png' })
  assert.equal(listingImage(null, ''), null)
})

test('broken images settle on a fallback and recover when a new source arrives', async () => {
  const props = vue.reactive({ src: '/broken.webp', image: null })
  const { state, mounted } = await imageState(props)
  state.element.value = { complete: true, naturalWidth: 0 }
  mounted()
  assert.equal(state.state.value, 'loading')
  state.handleError()
  assert.equal(state.state.value, 'error')
  props.src = '/replacement.webp'
  await vue.nextTick()
  await vue.nextTick()
  assert.equal(state.state.value, 'loading')
})
