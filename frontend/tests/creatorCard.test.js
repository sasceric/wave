import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { setupView } from './setupView.js'

const { descriptor } = parse(await readFile(new URL('../src/components/creators/CreatorCard.vue', import.meta.url), 'utf8'))
const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)

async function card(socialProfiles) {
  const props = { creator: { slug: 'creator', displayName: 'Creator', socialProfiles, category: 'Travel' }, imageSizes: undefined }
  const { state } = await setupView('../src/components/creators/CreatorCard.vue', {
    '../../lib/api': {
      formatFollowers: value => { assert.equal(typeof value, 'number'); return String(value) },
      formatMoney: String,
    },
  }, props)
  const app = vue.createSSRApp({ setup: () => ({ ...state, ...props }), render })
  for (const name of ['RouterLink', 'CardImage', 'PresentationDemoBadge', 'RatingSummary', 'MapPin']) {
    app.component(name, { setup: (_, { slots }) => () => vue.h('div', slots.default?.()) })
  }
  return renderToString(app)
}

test('creator cards omit the audience badge when a social profile has no valid follower count', async () => {
  for (const socialProfiles of [[], [{ platform: 'Instagram' }], ...[null, undefined, NaN, Infinity, -1].map(followers => [{ platform: 'Instagram', followers }])]) {
    const html = await card(socialProfiles)
    assert.doesNotMatch(html, /creator-card__metric|Instagram: null|Instagram: undefined/)
    assert.match(html, /Creator/)
  }
})

test('real zero and positive follower counts retain their audience badge', async () => {
  for (const followers of [0, 1200]) {
    const html = await card([{ platform: 'Instagram', followers }])
    assert.match(html, /creator-card__metric/)
    assert.ok(html.includes(`aria-label="Instagram: ${followers}"`))
  }
})
