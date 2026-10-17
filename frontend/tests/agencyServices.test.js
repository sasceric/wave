import assert from 'node:assert/strict'
import { test } from 'node:test'
import { normalizeServices, proposalFromOffering } from '../src/lib/agencyServices.js'
import { setupView } from './setupView.js'

test('legacy labels become structured drafts without inventing missing details', () => {
  const [service] = normalizeServices(['Vođenje kampanja'])
  assert.equal(service.title, 'Vođenje kampanja')
  assert.equal(service.description, '')
  assert.deepEqual(service.deliverables, [])
  assert.deepEqual(service.platforms, [])
  assert.equal(service.engagement, 'both')
  assert.deepEqual(normalizeServices([service]), [service])
})

test('offering proposals copy deliverables and only compatible prices, keeping originals unchanged', () => {
  const service = { title: 'Strategija', description: 'Opis usluge', deliverables: ['Brief', 'Izvještaj'] }
  const translate = (key, args) => key === 'studio.deliveryDescription' ? `Rok: ${args.count} dana` : key
  assert.deepEqual(proposalFromOffering(service, 'BAM', translate), { scope: 'Strategija\nOpis usluge\n• Brief\n• Izvještaj', amount: null, timeline: '' })
  const item = { title: 'Mjesečni paket', priceMode: 'from', price: 500, currency: 'BAM', deliveryDays: 10, deliverables: [{ quantity: 2, format: 'reel' }] }
  const result = proposalFromOffering(item, 'BAM', translate)
  assert.equal(result.amount, 500)
  assert.equal(result.timeline, 'Rok: 10 dana')
  assert.ok(result.scope.includes('2 × studio.reel'))
  assert.equal(proposalFromOffering(item, 'EUR', translate).amount, null)
  assert.equal(proposalFromOffering({ ...item, priceMode: 'agreement' }, 'BAM', translate).amount, null)
  result.scope = 'Custom scope'
  assert.equal(item.title, 'Mjesečni paket')
})

test('shared account tabs wrap keyboard navigation and preserve public section links', async () => {
  const items = [{ key: 'about' }, { key: 'packages' }, { key: 'faqs' }]
  const { state, documentTarget, emitted } = await setupView('../src/components/shared/ProfileTabs.vue', {}, { items, sections: false, modelValue: 'about' })
  let focused = -1
  const controls = items.map((_, i) => ({ focus: () => { focused = i }, getBoundingClientRect: () => ({ left: 0 }) }))
  state.root.value = { querySelectorAll: () => controls, scrollLeft: 0, scrollTo: () => {}, getBoundingClientRect: () => ({ left: 0 }) }
  documentTarget.activeElement = controls[2]
  let prevented = false
  state.keydown({ key: 'ArrowRight', preventDefault: () => { prevented = true } })
  assert.equal(focused, 0)
  assert.equal(prevented, true)
  assert.equal(emitted[0][0], 'update:modelValue')
  assert.equal(emitted[0][1], 'about')
})
