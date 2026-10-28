import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { setupView } from './setupView.js'
import { largestSocialProfile } from '../src/lib/socialAudience.js'

const { descriptor } = parse(await readFile(new URL('../src/components/creators/CreatorCard.vue', import.meta.url), 'utf8'))
const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)

async function card(socialProfiles) {
  const props = { creator: { slug: 'creator', displayName: 'Creator', socialProfiles, category: 'Travel' }, imageSizes: undefined }
  const { state } = await setupView('../src/components/creators/CreatorCard.vue', {
    '../../lib/socialAudience': { largestSocialProfile },
    '../../lib/api': {
      formatFollowers: value => { assert.equal(typeof value, 'number'); return String(value) },
      formatMoney: String,
    },
  }, props)
  const app = vue.createSSRApp({ setup: () => ({ ...state, ...props }), render })
  for (const name of ['RouterLink', 'CardImage', 'PresentationDemoBadge', 'RatingSummary', 'MapPin']) {
    app.component(name, { setup: (_, { slots }) => () => vue.h('div', slots.default?.()) })
  }
  app.component('SocialPlatformIcon', { props: ['platform'], setup: props => () => vue.h('span', { 'data-platform': props.platform }) })
  return renderToString(app)
}

test('creator cards omit the audience badge when a social profile has no valid follower count', async () => {
  for (const socialProfiles of [[], [{ platform: 'Instagram' }], ...[null, undefined, NaN, Infinity, -1].map(followers => [{ platform: 'Instagram', followers }])]) {
    const html = await card(socialProfiles)
    assert.doesNotMatch(html, /creator-card__metric|Instagram: null|Instagram: undefined/)
    assert.match(html, /Creator/)
  }
})

test('the badge shows the largest platform with its matching icon, regardless of saved order', async () => {
  const profiles = [{ platform: 'Instagram', followers: 10000 }, { platform: 'TikTok', followers: 20000 }, { platform: 'YouTube', followers: null }]
  for (const values of [profiles, profiles.toReversed()]) {
    const html = await card(values)
    assert.match(html, /aria-label="TikTok: 20000"/)
    assert.match(html, /data-platform="TikTok"/)
    assert.doesNotMatch(html, /Instagram: 10000/)
  }
  const instagram = await card([{ platform: 'TikTok', followers: 1000 }, { platform: 'Instagram', followers: 5000 }])
  assert.match(instagram, /data-platform="Instagram"/)
})

test('audience selection skips missing and invalid values, accepts legacy integer text, and keeps ties stable', () => {
  assert.equal(largestSocialProfile([{ platform: 'Instagram', followers: null }, { platform: 'TikTok', followers: '1200' }]).platform, 'TikTok')
  assert.equal(largestSocialProfile([{ followers: '1200' }]).followers, 1200)
  for (const value of [null, undefined, '', false, 'bad', -1, 1.5, Infinity, '100.5', 10000000000]) {
    assert.equal(largestSocialProfile([{ followers: value }]), null)
  }
  const tied = [{ platform: 'Instagram', followers: 2000 }, { platform: 'TikTok', followers: 2000 }]
  assert.equal(largestSocialProfile(tied).platform, 'Instagram')
  assert.deepEqual(tied.map(profile => profile.followers), [2000, 2000])
})

test('real zero and positive follower counts retain their audience badge', async () => {
  for (const followers of [0, 1200]) {
    const html = await card([{ platform: 'Instagram', followers }])
    assert.match(html, /creator-card__metric/)
    assert.ok(html.includes(`aria-label="Instagram: ${followers}"`))
  }
})
