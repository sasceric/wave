import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'

export async function setupView(path, extraModules = {}, props = {}, globals = {}) {
  const currentUser = vue.ref({ id: 1, accountType: 'creator' })
  const unreadMessageCount = vue.ref(0)
  const windowTarget = new EventTarget()
  windowTarget.requestAnimationFrame = () => 1
  windowTarget.cancelAnimationFrame = () => {}
  windowTarget.setTimeout = () => 1
  windowTarget.clearTimeout = () => {}
  const documentTarget = {
    visibilityState: 'visible',
    documentElement: { classList: { toggle: () => {} } },
  }
  const context = vm.createContext({
    window: windowTarget, document: documentTarget, navigator: {}, console, Event, URL,
    CustomEvent: class extends Event {
      constructor(type, options) { super(type); this.detail = options?.detail }
    },
    ...globals,
  })
  const modules = {
    vue: { ...vue, onMounted: () => {}, onBeforeUnmount: () => {} },
    'vue-router': { isNavigationFailure: () => false, NavigationFailureType: { duplicated: 16 }, RouterView: {}, useRoute: () => ({ query: {}, params: {}, meta: {}, fullPath: '/poruke' }), useRouter: () => ({ replace: async () => {} }) },
    'vue-i18n': { useI18n: () => ({ locale: vue.ref('bs'), t: (key) => key }) },
    '../composables/useCurrentUser': { currentUser, loadCurrentUser: async () => currentUser.value, setCurrentUser: (user) => { currentUser.value = user } },
    './composables/useCurrentUser': { currentUser, loadCurrentUser: async () => currentUser.value, setCurrentUser: (user) => { currentUser.value = user } },
    './composables/useUnreadMessages': { unreadMessageCount },
    '../../composables/useUnreadMessages': { unreadMessageCount },
    '../../composables/useMobileAccountSidebar': { mobileAccountSidebarOpen: vue.ref(false) },
    ...extraModules,
  }
  const source = await readFile(new URL(path, import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const script = compileScript(descriptor, { id: 'wave-test' })
  const module = new vm.SourceTextModule(script.content, {
    context, initializeImportMeta: (meta) => { meta.env = { DEV: true } },
  })
  await module.link((specifier, referencingModule) => {
    const requested = referencingModule.dependencySpecifiers.includes(specifier)
    assert.ok(requested)
    const values = modules[specifier]
    if (values) {
      return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value)
      }, { context })
    }
    // Components, icons, and formatting are unrelated to the inbox state tests.
    const importedNames = [...script.content.matchAll(/import\s*\{([^}]+)\}\s*from\s*['"]([^'"]+)['"]/g)]
      .filter((match) => match[2] === specifier)
      .flatMap((match) => match[1].split(',').map((name) => name.trim().split(/\s+as\s+/)[0]))
    return new vm.SyntheticModule(['default', ...importedNames], function () {
      this.setExport('default', {})
      for (const name of importedNames) this.setExport(name, () => {})
    }, { context })
  })
  await module.evaluate()
  const emitted = []
  const state = module.namespace.default.setup(props, { expose: () => {}, emit: (...args) => emitted.push(args) })
  return { state, currentUser, unreadMessageCount, windowTarget, documentTarget, emitted }
}
