import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import vm from 'node:vm'
import * as vue from 'vue'
import { setupView } from './setupView.js'

async function verification(request) {
  const context = vm.createContext({ console })
  const module = new vm.SourceTextModule(await readFile(new URL('../src/composables/useEmailVerification.js', import.meta.url), 'utf8'), { context })
  await module.link(name => {
    const values = name === 'vue' ? vue : { apiRequest: request }
    return new vm.SyntheticModule(Object.keys(values), function () {
      for (const [key, value] of Object.entries(values)) this.setExport(key, value)
    }, { context })
  })
  await module.evaluate()
  return module.namespace.useEmailVerification
}

test('verification resends share a request and ignore responses after account switch or verification', async () => {
  let calls = 0, finish
  const useVerification = await verification(async (path, options) => {
    calls++
    assert.equal(path, '/auth/verification-email')
    assert.equal(options.method, 'POST')
    return new Promise(resolve => { finish = resolve })
  })
  const user = vue.ref({ id: 1, emailVerified: false })
  const banner = useVerification(user), checklist = useVerification(user)
  const a = banner.resend(), b = checklist.resend()
  assert.equal(calls, 1)
  user.value = { id: 2, emailVerified: false }
  finish({ message: 'Sent to previous user' })
  await Promise.all([a, b])
  assert.equal(banner.notice.value, '')
  assert.equal(checklist.notice.value, '')
  const c = banner.resend()
  user.value.emailVerified = true
  finish({ message: 'Sent' })
  await c
  assert.equal(banner.notice.value, '')
  await banner.resend()
  assert.equal(calls, 2)
})

test('verification failures remain visible, allow retry and never report success', async () => {
  const useVerification = await verification(async () => { throw new Error('Rate limited') })
  const state = useVerification(vue.ref({ id: 1, emailVerified: false }))
  await state.resend()
  assert.equal(state.error.value, 'Rate limited')
  assert.equal(state.notice.value, '')
  assert.equal(state.sending.value, false)
  await state.resend()
  assert.equal(state.error.value, 'Rate limited')
})

test('refreshing the same unverified account preserves the in-flight resend result', async () => {
  let finish
  const useVerification = await verification(() => new Promise(resolve => { finish = resolve }))
  const user = vue.ref({ id: 7, emailVerified: false })
  const state = useVerification(user)
  const request = state.resend()
  user.value = { id: 7, emailVerified: false, profileComplete: true }
  assert.equal(state.sending.value, true)
  finish({ message: 'Sent' })
  await request
  assert.equal(state.notice.value, 'Sent')
})

test('persistent verification notice refreshes on inbox resume and removes its listener', async () => {
  let mount, unmount, refreshed = 0
  const currentUser = vue.ref({ id: 1, emailVerified: false, email: 'pending@example.test' })
  const { state, windowTarget } = await setupView('../src/components/shared/EmailVerificationNotice.vue', {
    vue: { ...vue, onMounted: callback => { mount = callback }, onBeforeUnmount: callback => { unmount = callback } },
    '../../composables/useCurrentUser': { currentUser, loadCurrentUser: async () => { refreshed++; currentUser.value.emailVerified = true } },
  })
  mount()
  windowTarget.dispatchEvent(new Event('wave:inbox-refresh'))
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(refreshed, 1)
  assert.equal(currentUser.value.emailVerified, true)
  assert.equal(state.refreshing.value, false)
  unmount()
  currentUser.value.emailVerified = false
  windowTarget.dispatchEvent(new Event('wave:inbox-refresh'))
  assert.equal(refreshed, 1)
})
