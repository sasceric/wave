import assert from 'node:assert/strict'
import test from 'node:test'
import * as vue from 'vue'
import { setupView } from './setupView.js'

const example = key => ({
  key, subject: `Subject ${key}`, defaultSubject: `Subject ${key}`, htmlBody: '<html><body>{{heading}}</body></html>',
  defaultHtmlBody: '<html><body>{{heading}}</body></html>', customized: false, customizedLocales: [],
  text: [{ key: 'heading', default: 'Default heading', value: 'Default heading', multiline: false }],
  variables: ['heading'], preview: { heading: 'Default heading' },
})
const payload = () => ({ data: { templates: ['verify', 'campaign_invitation'].map(example), sender: { name: 'Wave test', email: 'sender@example.test' } } })
const flush = async () => { await vue.nextTick(); await new Promise(resolve => setImmediate(resolve)) }
async function editor(get = async () => payload(), request = async () => ({ data: { customized: true } })) {
  const language = vue.ref('bs')
  let leave
  const calls = []
  const view = await setupView('../src/components/admin/AdminEmailTemplates.vue', {
    'vue-i18n': { useI18n: () => ({ locale: language, t: key => key }) },
    'vue-router': { useRoute: () => ({ query: {} }), onBeforeRouteLeave: callback => { leave = callback } },
    '../../i18n': { localeNames: { bs: 'Bosanski', en: 'English' } },
    '../../lib/emailTemplateCatalog': await import('../src/lib/emailTemplateCatalog.js'),
    '../../lib/api': { apiGet: get, apiRequest: async (path, options) => { calls.push({ path, options }); return request(path, options) } },
  })
  await view.state.loadTemplates()
  return { ...view, calls, leave: () => leave() }
}

test('editing, preserving and discarding drafts keeps template and locale scopes separate', async () => {
  const { state } = await editor()
  assert.equal(state.dirty.value, false)
  state.draft.value.text.heading = 'My wording'
  assert.equal(state.previewHtml.value, '<html><body>My wording</body></html>')
  state.selectTemplate('campaign_invitation')
  assert.equal(state.selectedKey.value, 'verify')
  state.cancelPending()
  assert.equal(state.draft.value.text.heading, 'My wording')
  state.selectTemplate('campaign_invitation')
  state.discardAndContinue()
  assert.equal(state.selectedKey.value, 'campaign_invitation')
  assert.equal(state.draft.value.text.heading, 'Default heading')
  assert.equal(state.dirty.value, false)
})

test('language requests cannot save stale copy or overwrite the latest selected language', async () => {
  const pending = []
  let first = true
  const { state, calls } = await editor(async (path, options) => {
    if (first) { first = false; return payload() }
    return new Promise(resolve => pending.push({ locale: options.locale, resolve }))
  })
  state.selectLocale('en')
  await state.saveTemplate()
  assert.equal(calls.length, 0, 'Previous language must never be saved to the new one.')
  await flush()
  assert.equal(state.loading.value, true)
  assert.equal(state.draft.value, null)
  state.selectTemplate('campaign_invitation')
  assert.equal(state.selectedKey.value, 'verify')
  state.selectLocale('hr')
  await flush()
  pending.find(item => item.locale === 'hr').resolve(payload())
  await flush()
  pending.find(item => item.locale === 'en').resolve({ data: { ...payload().data, templates: [{ ...example('verify'), subject: 'Wrong old response' }] } })
  await flush()
  assert.equal(state.loadedLocale.value, 'hr')
  assert.equal(state.draft.value.subject, 'Subject verify')
})

test('saving and testing target the selected locale and never erase an unsaved test draft', async () => {
  const { state, calls } = await editor()
  state.draft.value.text.heading = 'Custom text'
  await state.sendTest()
  assert.match(calls[0].path, /verify\/test$/)
  assert.equal(calls[0].options.locale, 'bs')
  assert.equal(state.dirty.value, true)
  await state.saveTemplate()
  assert.equal(calls[1].options.method, 'PUT')
  assert.equal(calls[1].options.body.text.heading, 'Custom text')
  assert.equal(state.dirty.value, false)
  assert.deepEqual([...state.selectedTemplate.value.customizedLocales], ['bs'])
})

test('navigation and browser refresh protect unsaved changes', async () => {
  const { state, leave } = await editor()
  state.draft.value.subject = 'Unsaved'
  const cancelled = leave()
  state.cancelPending()
  assert.equal(await cancelled, false)
  const accepted = leave()
  state.discardAndContinue()
  assert.equal(await accepted, true)
  state.draft.value = { ...example('verify'), text: {} }
  let prevented = false
  state.beforeUnload({ preventDefault: () => { prevented = true } })
  assert.equal(prevented, true)
})

test('a failed language load leaves no stale editable draft and supports retry', async () => {
  let fail = false
  const { state, calls } = await editor(async () => { if (fail) throw new Error('Unavailable'); return payload() })
  fail = true
  state.selectLocale('en')
  await flush()
  assert.equal(state.draft.value, null)
  assert.equal(state.loadedLocale.value, '')
  await state.sendTest()
  assert.equal(calls.length, 0)
  fail = false
  await state.loadTemplates()
  assert.equal(state.loadedLocale.value, 'en')
  assert.equal(state.error.value, '')
})

test('preview branding uses local assets without permitting arbitrary external images or active content', async () => {
  const { state } = await setupView('../src/components/shared/EmailPreviewFrame.vue', {}, {
    html: '<img src="http://127.0.0.1:8000/images/email/wave-logo-v1.png"><img src="https://example.test/tracker.png">',
    title: 'Preview', allowBrandImages: true,
  }, { window: { location: { origin: 'http://127.0.0.1:5173' } } })
  assert.match(state.source.value, /src="http:\/\/127.0.0.1:5173\/images\/email\/wave-logo-v1.png"/)
  const csp = state.source.value.split('>')[0]
  assert.doesNotMatch(csp, /example\.test|127\.0\.0\.1:8000/)
  assert.match(csp, /default-src 'none'/)
  assert.match(csp, /form-action 'none'/)
})

test('desktop and mobile previews retain their actual email widths in narrow editor columns', async () => {
  const { state } = await editor()
  state.previewWidth.value = 300
  assert.equal(state.previewFrameStyle.value.width, '640px')
  assert.equal(state.previewFrameStyle.value.transform, 'scale(0.46875)')
  state.device.value = 'mobile'
  assert.equal(state.previewFrameStyle.value.width, '375px')
  assert.equal(state.previewFrameStyle.value.transform, 'scale(0.8)')
})

test('reset clears only the selected language and reloads the built-in draft', async () => {
  const { state, calls } = await editor()
  state.draft.value.text.heading = 'Unsaved draft'
  state.confirmReset.value = true
  await state.resetTemplate()
  assert.equal(calls[0].options.method, 'DELETE')
  assert.equal(calls[0].options.locale, 'bs')
  assert.match(calls[0].path, /verify$/)
  assert.equal(state.confirmReset.value, false)
  assert.equal(state.draft.value.text.heading, 'Default heading')
  assert.equal(state.dirty.value, false)
})
