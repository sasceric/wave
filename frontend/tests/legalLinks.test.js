import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { compile } from '@vue/compiler-dom'
import { parse } from '@vue/compiler-sfc'
import { baseCompile } from '@intlify/message-compiler'
import * as vue from 'vue'
import { createI18n } from 'vue-i18n'
import { renderToString } from 'vue/server-renderer'
import { setupView } from './setupView.js'

// Production locale messages contain compiled AST objects, including URL values.
function precompile(value) {
  if (typeof value === 'string') return baseCompile(value, { jit: true, location: false }).ast
  if (Array.isArray(value)) return value.map(precompile)
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, precompile(item)]))
}

const source = await readFile(new URL('../src/views/LegalView.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const render = new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(vue)

test('legal links resolve compiled locale URLs instead of rendering object Object', async () => {
  for (const locale of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr', 'de']) {
    const { legal } = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8'))
    const i18n = createI18n({ legacy: false, locale, messages: { [locale]: { legal: precompile(legal), app: { footerLegal: 'Legal' } } } })
    for (const [routeName, key] of [['privacy-policy', 'privacy'], ['cookie-policy', 'cookies'], ['terms-of-use', 'terms']]) {
      const { state } = await setupView('../src/views/LegalView.vue', {
        '../../../config/legal_documents.json': { default: JSON.parse(await readFile(new URL('../../config/legal_documents.json', import.meta.url), 'utf8')) },
        '../lib/api': { formatDate: value => value },
        'vue-i18n': { useI18n: () => i18n.global },
        'vue-router': { useRoute: () => ({ meta: { routeName } }) },
      })
      const app = vue.createSSRApp({ setup: () => ({ ...state }), render })
      app.component('ArrowRight', { render: () => null })
      const html = await renderToString(app)
      for (const link of legal[key].sections.flatMap(section => section.links || [])) {
        assert.ok(html.includes(`href="${link.url}"`), `${locale}: ${link.url}`)
      }
      assert.doesNotMatch(html, /\[object Object\]|%5Bobject/)
    }
  }
})
