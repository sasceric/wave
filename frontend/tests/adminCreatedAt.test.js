import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { reactive, ref } from 'vue'
import { setupView } from './setupView.js'
import { createChatTimeFormatter } from '../src/lib/chatTime.js'

process.env.TZ = 'Europe/Sarajevo'

for (const section of ['creators', 'companies', 'registrations', 'campaigns']) {
  test(`${section} tables default to created time descending in both the column and API request`, async () => {
    const requests = []
    const { state } = await setupView('../src/views/AdminDashboardView.vue', {
      'vue-router': { useRoute: () => ({ meta: { adminSection: section }, query: {} }) },
      '../lib/api': { formatMoney: String, apiRequest: async () => ({}), apiGet: async path => { requests.push(path); return { data: [], meta: { total: 0, page: 1 } } } },
    })
    assert.equal(state.tableColumns.value.find(column => column.defaultSort)?.key, 'createdAt')
    assert.equal(state.listOptions.value.direction, 'desc')
    await state.loadList()
    const params = new URLSearchParams(requests[0].split('?')[1])
    assert.equal(params.get('sort'), 'createdAt')
    assert.equal(params.get('direction'), 'desc')
  })
}

test('created time uses full relative text below 24 hours and switches to the exact local timestamp at 24 hours', async () => {
  const now = Date.parse('2026-10-23T12:13:00Z')
  const props = reactive({ value: '2026-10-23T11:23:00Z', now })
  const { state } = await setupView('../src/components/admin/AdminCreatedAt.vue', {
    'vue-i18n': { useI18n: () => ({ locale: ref('en'), t: (key, args) => args ? `${args.date} at ${args.time}` : key }) },
    '../../lib/chatTime': { createChatTimeFormatter },
  }, props)
  assert.equal(state.label.value, '50 minutes ago')
  assert.equal(state.exact.value, '23.10.2026 at 13:23')
  for (const [minutes, expected] of [[59, '59 minutes ago'], [60, '1 hour ago'], [240, '4 hours ago'], [1380, '23 hours ago'], [1439, '23 hours ago']]) {
    props.value = new Date(now - minutes * 60_000).toISOString()
    assert.equal(state.label.value, expected)
  }
  props.value = '2026-10-22T12:14:00Z'
  assert.equal(state.label.value, '23 hours ago')
  props.value = '2026-10-22T12:13:00Z'
  assert.equal(state.label.value, '22.10.2026 at 14:13')
  props.value = '2026-10-23T11:23:00Z'
  props.now = Date.parse('2026-10-24T11:23:00Z')
  assert.equal(state.label.value, '23.10.2026 at 13:23')
  for (const value of [null, '', 'invalid']) {
    props.value = value
    assert.equal(state.label.value, '')
  }
})

test('created timestamps use the date connector and full relative text in all seven languages', async () => {
  for (const language of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']) {
    const copy = JSON.parse(await readFile(new URL(`../src/locales/${language}.json`, import.meta.url), 'utf8'))
    const props = reactive({ value: '2026-10-22T12:13:00Z', now: Date.parse('2026-10-23T12:13:00Z') })
    const { state } = await setupView('../src/components/admin/AdminCreatedAt.vue', {
      'vue-i18n': { useI18n: () => ({ locale: ref(language), t: (key, args = {}) => key.split('.').reduce((value, part) => value[part], copy).replace(/\{(\w+)\}/g, (_, part) => args[part]) }) },
      '../../lib/chatTime': { createChatTimeFormatter },
    }, props)
    assert.ok(copy.adminProfiles.createdAt)
    assert.ok(copy.adminProfiles.createdAtUnknown)
    assert.match(state.label.value, /^22\.10\.2026 .+ 14:13$/)
    if (language === 'bs') assert.equal(state.label.value, '22.10.2026 u 14:13')
    props.value = '2026-10-23T11:23:00Z'
    assert.match(state.label.value, /50/)
    assert.doesNotMatch(state.label.value, /23\.10\.2026/)
  }
})
