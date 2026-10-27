import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { referenceImage, workImage, workReferences } from '../src/lib/creatorPresentation.js'
import { setupView } from './setupView.js'

const video = { type: 'video', platform: 'YouTube', url: 'https://youtu.be/abcdefghijk', embedUrl: 'https://www.youtube-nocookie.com/embed/abcdefghijk' }
const coverUrl = '/media/portfolio-cover.webp'

test('work details pass the same cover used by the portfolio card to reference previews', async () => {
  for (const work of [
    { id: 'legacy-work', title: 'Existing video', ...video, coverUrl },
    { id: 'reference-work', title: 'New video', coverUrl, references: [video] },
  ]) {
    const props = { items: [work], name: 'Creator', showHeading: false }
    const { state } = await setupView('../src/components/creators/CreatorWorks.vue', {
      '../../lib/creatorPresentation': { workImage, workReferences },
    }, props)
    state.selected.value = work
    const { descriptor } = parse(await readFile(new URL('../src/components/creators/CreatorWorks.vue', import.meta.url), 'utf8'))
    const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)
    const app = vue.createSSRApp({ setup: () => ({ ...state, ...props }), render })
    app.component('FormModal', { setup: (_, { slots }) => () => vue.h('div', slots.default?.()) })
    app.component('WorkReferences', { props: ['items', 'name', 'coverUrl'], setup: props => () => vue.h('div', { 'data-reference-cover': props.coverUrl }) })
    for (const icon of ['Pin', 'ArrowUpRight', 'BriefcaseBusiness', 'CalendarDays', 'Clapperboard']) app.component(icon, { render: () => null })
    const html = await renderToString(app)
    assert.ok(html.includes(`src="${coverUrl}"`))
    assert.ok(html.includes(`data-reference-cover="${coverUrl}"`))
  }
})

test('the first video reference keeps the work cover without replacing its video or post URLs', async () => {
  const { state } = await setupView('../src/components/creators/WorkReferences.vue', {
    '../../lib/creatorPresentation': { referenceImage },
  }, { items: [video], name: 'Creator', coverUrl })
  const preview = state.preview(video, 0)
  assert.equal(preview.coverUrl, coverUrl)
  assert.equal(preview.url, video.url)
  assert.equal(preview.embedUrl, video.embedUrl)
  assert.equal(state.preview(video, 1).coverUrl, '')
  assert.equal(state.preview({ ...video, coverUrl: '/media/reference-specific.webp' }, 0).coverUrl, '/media/reference-specific.webp')
  assert.equal(state.preview({ type: 'image', mediaId: 1, imageUrl: '/media/actual-photo.webp', url: 'https://instagram.com/p/photo' }, 0).url, '/media/actual-photo.webp')
  assert.equal(state.preview({ type: 'image', mediaId: 1, imageUrl: '/media/actual-photo.webp' }, 0).coverUrl, '')
})
