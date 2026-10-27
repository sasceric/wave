import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import test from 'node:test'
import { ref, shallowRef } from 'vue'
import { setupView } from './setupView.js'

async function maintenanceModule(apiGet) {
  const context = vm.createContext({})
  const source = await readFile(new URL('../src/composables/useMaintenance.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(path => {
    const exports = path === 'vue' ? { shallowRef } : { apiGet }
    return new vm.SyntheticModule(Object.keys(exports), function () {
      for (const [key, value] of Object.entries(exports)) this.setExport(key, value)
    }, { context })
  })
  await module.evaluate()
  return module.namespace
}

test('maintenance guards public and account links while preserving admin access and recovery', async () => {
  const { maintenanceDestination } = await maintenanceModule()
  const route = name => ({ meta: { routeName: name } })
  for (const name of ['home', 'creator-profile', 'messages', 'account-settings', 'admin', 'register', 'not-found']) {
    assert.equal(maintenanceDestination(route(name), { enabled: true, bypass: false }).name, 'maintenance')
    assert.equal(maintenanceDestination(route(name), { enabled: true, bypass: true }), null)
    assert.equal(maintenanceDestination(route(name), { enabled: false, bypass: false }), null)
  }
  for (const name of ['maintenance', 'login', 'forgot-password', 'reset-password']) {
    assert.equal(maintenanceDestination(route(name), { enabled: true, bypass: false }), null)
  }
  assert.equal(maintenanceDestination(route('maintenance'), { enabled: false, bypass: false }).name, 'home')
  assert.equal(maintenanceDestination(route('maintenance'), { enabled: false, bypass: true }), null)
})

test('simultaneous maintenance checks share one request but later navigation gets fresh status', async () => {
  let calls = 0
  let enabled = true
  const { loadMaintenance, maintenanceState } = await maintenanceModule(async () => {
    calls += 1
    return { data: { enabled, bypass: false } }
  })
  await Promise.all([loadMaintenance(), loadMaintenance()])
  assert.equal(calls, 1)
  assert.equal(maintenanceState.value.enabled, true)
  enabled = false
  await loadMaintenance()
  assert.equal(calls, 2)
  assert.equal(maintenanceState.value.enabled, false)
})

test('admin switch waits for a successful save and keeps the last setting on failure', async () => {
  const writes = []
  const maintenanceState = shallowRef({ enabled: false, bypass: true })
  let fail = false
  const user = ref({ isAdmin: true })
  const { state } = await setupView('../src/components/account/MaintenanceSettings.vue', {
    '../../composables/useMaintenance': { maintenanceState, loadMaintenance: async () => maintenanceState.value },
    '../../composables/useCurrentUser': { currentUser: user },
    '../../lib/api': { apiRequest: async (path, options) => {
      writes.push({ path, ...options })
      if (fail) throw new Error('Save failed')
      return { data: { enabled: options.body.enabled, bypass: true } }
    } },
  })
  state.loading.value = false
  await state.update(1)
  assert.equal(writes[0].path, '/admin/maintenance')
  assert.equal(writes[0].body.enabled, true)
  assert.equal(maintenanceState.value.enabled, true)
  fail = true
  await state.update(0)
  assert.equal(maintenanceState.value.enabled, true)
  assert.equal(state.error.value, 'Save failed')
  user.value = { isAdmin: false }
  await state.update(0)
  assert.equal(writes.length, 2)
})

test('retry stays on maintenance while enabled and returns home after it is switched off', async () => {
  const navigations = []
  let enabled = true
  const { state } = await setupView('../src/views/MaintenanceView.vue', {
    'vue-router': { useRouter: () => ({ replace: async destination => navigations.push(destination) }) },
    '../composables/useMaintenance': { maintenanceState: shallowRef({ enabled: true, bypass: false }), loadMaintenance: async () => ({ enabled }) },
    '../routePaths': { localizedRouteName: (name, locale) => `${name}-${locale}` },
  })
  await state.checkAgain()
  assert.equal(state.checked.value, true)
  assert.equal(navigations.length, 0)
  enabled = false
  await state.checkAgain()
  assert.equal(navigations[0].name, 'home-bs')
})

test('JSON writes and uploads notify the app about maintenance instead of following an API redirect', async () => {
  const window = new EventTarget()
  let events = 0
  window.addEventListener('wave:maintenance', () => { events += 1 })
  const context = vm.createContext({
    window, FormData, CustomEvent: class extends Event {},
    fetch: async url => url.includes('/auth/csrf')
      ? { ok: true, json: async () => ({ csrfToken: 'test-only' }) }
      : { ok: false, status: 503, json: async () => ({ code: 'maintenance', error: 'Temporarily unavailable' }) },
  })
  const source = await readFile(new URL('../src/lib/api.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  await module.link(path => path.includes('actionFeedback')
    ? new vm.SyntheticModule(['actionFeedback'], function () { this.setExport('actionFeedback', () => {}) }, { context })
    : new vm.SyntheticModule(['default'], function () { this.setExport('default', { global: { locale: { value: 'bs' }, t: key => key } }) }, { context }))
  await module.evaluate()
  await assert.rejects(module.namespace.apiRequest('/me/profile', { method: 'PUT', body: {} }), error => error.code === 'maintenance')
  await assert.rejects(module.namespace.apiUpload('/media', new Blob(['test'])), error => error.code === 'maintenance')
  assert.equal(events, 2)
})

test('login and recovery can disable public catalog reads without opening a maintenance exception', async () => {
  let calls = 0
  let enabled = false
  const context = vm.createContext({})
  const source = await readFile(new URL('../src/composables/useMarketplaceCatalog.js', import.meta.url), 'utf8')
  const module = new vm.SourceTextModule(source, { context })
  const vue = await import('vue')
  await module.link(path => {
    const exports = path === 'vue' ? { ...vue, onMounted: () => {}, onBeforeUnmount: () => {} }
      : { apiGet: async () => { calls += 1; return { data: ['Travel'] } } }
    return new vm.SyntheticModule(Object.keys(exports), function () {
      for (const [key, value] of Object.entries(exports)) this.setExport(key, value)
    }, { context })
  })
  await module.evaluate()
  const catalog = module.namespace.useMarketplaceCatalog(ref('bs'), { enabled: () => enabled })
  await catalog.refresh()
  assert.equal(calls, 0)
  enabled = true
  await catalog.refresh()
  assert.equal(calls, 1)
  assert.equal(catalog.categories.value[0], 'Travel')
})
