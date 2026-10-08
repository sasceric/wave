import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

test('lazy catalogs load once, preserve every language and cannot overwrite a newer language selection', async () => {
  const catalogs = {}
  for (const code of ['bs', 'hr', 'sr', 'sl', 'en', 'cnr']) catalogs[code] = JSON.parse(await readFile(new URL(`../src/locales/${code}.json`, import.meta.url), 'utf8'))
  const document = { documentElement: {}, querySelector: () => null }
  const context = vm.createContext({ window: { localStorage: { getItem: () => 'bs', setItem: () => {} } }, document, Map })
  let global
  const modules = {
    'vue-i18n': { createI18n: (options) => {
      const messages = { ...options.messages }
      global = { locale: { value: options.locale }, get availableLocales() { return Object.keys(messages) }, setLocaleMessage: (code, catalog) => { messages[code] = catalog }, t: () => 'Wave' }
      return { global }
    } },
    './locales/bs.json': { default: catalogs.bs },
  }
  async function synthetic(exports) {
    const module = new vm.SyntheticModule(Object.keys(exports), function () { for (const [key, value] of Object.entries(exports)) this.setExport(key, value) }, { context })
    await module.link(() => {})
    await module.evaluate()
    return module
  }
  let resolveEnglish
  let englishRequests = 0
  const source = await readFile(new URL('../src/i18n.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, {
    context,
    importModuleDynamically: async (path) => {
      const code = path.match(/([a-z]+)\.json$/)[1]
      if (code === 'en') { englishRequests++; await new Promise(resolve => { resolveEnglish = resolve }) }
      return synthetic({ default: catalogs[code] })
    },
  })
  await module.link(path => synthetic(modules[path]))
  await module.evaluate()
  await module.namespace.initialLocaleReady
  assert.equal(global.locale.value, 'bs')
  const first = module.namespace.setLocale('en')
  const second = module.namespace.setLocale('en')
  await new Promise(resolve => setImmediate(resolve))
  await module.namespace.setLocale('hr')
  resolveEnglish()
  await Promise.all([first, second])
  assert.equal(englishRequests, 1)
  assert.equal(global.locale.value, 'hr')
  for (const code of ['en', 'sr', 'sl', 'cnr', 'bs']) {
    await module.namespace.setLocale(code)
    assert.equal(global.locale.value, code)
    assert.equal(document.documentElement.lang, code === 'cnr' ? 'sr-Latn-ME' : code === 'sr' ? 'sr-Latn' : code)
    assert.ok(global.availableLocales.includes(code))
    assert.ok(catalogs[code].app.campaigns)
  }
  assert.equal(englishRequests, 1)
})
