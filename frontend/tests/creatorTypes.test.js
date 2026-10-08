import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import vm from 'node:vm'

test('the shared creator type catalog has usable labels and controls in all six languages', async () => {
  const types = JSON.parse(await readFile(new URL('../../config/creator_types.json', import.meta.url), 'utf8'))
  const context = vm.createContext({})
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/creatorTypes.js', import.meta.url), 'utf8'), { context })
  await module.link(() => new vm.SyntheticModule(['default'], function () { this.setExport('default', types) }, { context }))
  await module.evaluate()
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']) {
    const copy = JSON.parse(await readFile(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8')).creatorTypes
    const options = module.namespace.creatorTypeOptions(key => key.split('.').slice(1).reduce((value, part) => value[part], copy))
    assert.deepEqual(Array.from(options, option => option.value), types)
    assert.ok(options.every(option => typeof option.label === 'string' && option.label.length > 0))
    for (const key of ['label', 'select', 'all', 'search', 'empty', 'hint']) assert.ok(copy[key]?.length)
  }
})
