import assert from 'node:assert/strict'
import test from 'node:test'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

async function fixture(response, fails = false, stalled = false) {
  const requests = []
  const context = vm.createContext({ URLSearchParams, AbortController, setTimeout, clearTimeout })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/lib/publicRedirects.js', import.meta.url), 'utf8'), { context })
  await module.link(() => new vm.SyntheticModule(['apiGet'], function () {
    this.setExport('apiGet', async (path, { signal }) => {
      requests.push(path)
      if (stalled) return new Promise((_, reject) => signal.addEventListener('abort', () => reject(new Error('Aborted')), { once: true }))
      if (fails) throw new Error('Unavailable')
      return response
    })
  }, { context }))
  await module.evaluate()
  return { resolve: module.namespace.resolvePublicRedirect, requests }
}

test('SPA links resolve old URLs and preserve the complete target query without a history loop', async () => {
  const { resolve, requests } = await fixture({ data: { targetPath: '/kreatori?tag=a&tag=b', statusCode: 301 } })
  const target = await resolve({ meta: { routeName: 'creator-profile' }, fullPath: '/kreatori/deleted?utm_source=poster#bio' })
  assert.equal(target.path, '/kreatori?tag=a&tag=b')
  assert.equal(target.replace, true)
  assert.equal(new URLSearchParams(requests[0].split('?')[1]).get('path'), '/kreatori/deleted?utm_source=poster')
})

test('a stalled redirect lookup is aborted and releases public navigation', async () => {
  const { resolve, requests } = await fixture(null, false, true)
  assert.equal(await resolve({ meta: { routeName: 'creators' }, fullPath: '/kreatori' }, 10), undefined)
  assert.equal(requests.length, 1)
  assert.equal(await resolve({ meta: { routeName: 'campaigns' }, fullPath: '/kampanje' }, 10), undefined)
  assert.equal(requests.length, 2, 'a previous timeout must not lock subsequent links')
})

test('private pages never request redirects and failed or unsafe lookups do not block navigation', async () => {
  const { resolve, requests } = await fixture({ data: null })
  for (const name of ['account', 'account-campaigns', 'admin', 'admin-redirects', 'messages', 'support-track', 'verify-email']) {
    assert.equal(await resolve({ meta: { routeName: name }, fullPath: '/private' }), undefined)
  }
  assert.equal(requests.length, 0)
  for (const data of [null, { targetPath: '//example.com' }, { targetPath: 'https://example.com' }, { targetPath: '/same' }]) {
    const f = await fixture({ data })
    assert.equal(await f.resolve({ meta: {}, fullPath: '/same' }), undefined)
  }
  const failed = await fixture(null, true)
  assert.equal(await failed.resolve({ meta: {}, fullPath: '/unknown' }), undefined)
})
