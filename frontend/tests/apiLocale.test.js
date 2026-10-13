import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'

async function client(locale = 'bs') {
  const calls = []
  const i18n = { global: { locale: { value: locale }, t: key => key } }
  const context = vm.createContext({
    FormData,
    fetch: async (url, options) => {
      calls.push({ url, options })
      return { ok: true, json: async () => url.startsWith('/api/auth/csrf') ? { csrfToken: 'test-only' } : { data: null } }
    },
  })
  const source = await readFile(new URL('../src/lib/api.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(() => new vm.SyntheticModule(['default'], function () { this.setExport('default', i18n) }, { context }))
  await module.evaluate()
  return { api: module.namespace, calls, i18n }
}

test('default Bosnian API reads, writes, CSRF and uploads omit the locale query parameter', async () => {
  const { api, calls } = await client()
  await api.apiGet('/auth/session')
  await api.apiGet('/creators?page=2')
  await api.apiRequest('/auth/login', { method: 'POST', body: { email: 'demo@example.test' } })
  await api.apiUpload('/media', new Blob(['demo'], { type: 'image/png' }))
  assert.deepEqual(calls.map(call => call.url), ['/api/auth/session', '/api/creators?page=2', '/api/auth/csrf', '/api/auth/login', '/api/media'])
  assert.equal(calls[3].options.headers['X-CSRF-Token'], 'test-only')
})

test('non-default languages retain locale and explicit Bosnian overrides omit it', async () => {
  for (const locale of ['hr', 'sr', 'cnr', 'sl', 'en']) {
    const { api, calls } = await client(locale)
    await api.apiGet('/creators?page=2')
    await api.apiRequest('/auth/login', { method: 'POST', body: {} })
    await api.apiUpload('/media', new Blob(['demo']))
    await api.apiGet('/auth/session', { locale: 'bs' })
    assert.deepEqual(calls.map(call => call.url), [`/api/creators?page=2&locale=${locale}`, `/api/auth/csrf?locale=${locale}`, `/api/auth/login?locale=${locale}`, `/api/media?locale=${locale}`, '/api/auth/session'])
  }
})

test('simultaneous session reads share a request but later reads remain fresh', async () => {
  const { api, calls } = await client()
  await Promise.all([api.apiGet('/auth/session'), api.apiGet('/auth/session')])
  assert.equal(calls.length, 1)
  await api.apiGet('/auth/session')
  assert.equal(calls.length, 2)
  await Promise.all([api.apiGet('/auth/session'), api.apiGet('/auth/session', { locale: 'en' })])
  assert.deepEqual(calls.slice(2).map(call => call.url), ['/api/auth/session', '/api/auth/session?locale=en'])
})

test('an authentication mutation invalidates pending session sharing', async () => {
  const { api, calls } = await client()
  const before = api.apiGet('/auth/session')
  const logout = api.apiRequest('/auth/logout', { method: 'POST' })
  const after = api.apiGet('/auth/session')
  await Promise.all([before, logout, after])
  assert.equal(calls.filter(call => call.url === '/api/auth/session').length, 2)
})
