import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { renderToString } from 'vue/server-renderer'
import { CAMPAIGN_PLACEHOLDER } from '../src/lib/marketplace.js'

const { descriptor } = parse(await readFile(new URL('../src/components/admin/AdminProfileIdentity.vue', import.meta.url), 'utf8'))
const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)

for (const image of ['/media/campaign-cover.webp', null]) {
  test(`campaign identities show ${image ? 'their cover' : 'the shared placeholder'} before the title`, async () => {
    const row = { title: 'Campaign name', coverImageUrl: image, company: { name: 'Brand' } }
    const app = vue.createSSRApp({ setup: () => ({ row, campaign: true, CAMPAIGN_PLACEHOLDER, t: key => key }), render })
    app.component('CardImage', { props: ['src'], setup: props => () => vue.h('img', { src: props.src }) })
    const html = await renderToString(app)
    assert.ok(html.includes(`src="${image || CAMPAIGN_PLACEHOLDER}"`))
    assert.ok(html.indexOf('<img') < html.indexOf('<strong>Campaign name'))
    assert.match(html, /admin-profile-identity--campaign/)
  })
}
