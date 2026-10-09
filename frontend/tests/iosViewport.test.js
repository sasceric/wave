import assert from 'node:assert/strict'
import test from 'node:test'
import { configureIOSViewport } from '../src/lib/iosViewport.js'

const original = 'width=device-width, initial-scale=1.0, viewport-fit=cover'
function viewportDocument(content = original) {
  const viewport = { content }
  return { viewport, querySelector: selector => selector === 'meta[name="viewport"]' ? viewport : null }
}

test('iPhone focus zoom is constrained without changing the original layout directives', () => {
  const doc = viewportDocument()
  configureIOSViewport(doc, { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', platform: 'iPhone' })
  assert.equal(doc.viewport.content, `${original}, maximum-scale=1.0`)
  assert.doesNotMatch(doc.viewport.content, /user-scalable=no|minimum-scale=/)
})

test('desktop-mode iPad gets the same input-focus behavior', () => {
  const doc = viewportDocument()
  configureIOSViewport(doc, { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X)', platform: 'MacIntel', maxTouchPoints: 5 })
  assert.equal(doc.viewport.content, `${original}, maximum-scale=1.0`)
})

test('Android and desktop viewport scaling stays untouched', () => {
  for (const device of [
    { userAgent: 'Mozilla/5.0 (Linux; Android 15)', platform: 'Linux', maxTouchPoints: 5 },
    { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X)', platform: 'MacIntel', maxTouchPoints: 0 },
    { userAgent: 'Mozilla/5.0 (Windows NT 10.0)', platform: 'Win32', maxTouchPoints: 5 },
  ]) {
    const doc = viewportDocument()
    configureIOSViewport(doc, device)
    assert.equal(doc.viewport.content, original)
  }
})

test('repeated startup replaces the scale limit without duplicating viewport directives', () => {
  const doc = viewportDocument(`${original}, maximum-scale = 5`)
  const device = { userAgent: 'iPhone', platform: 'iPhone' }
  configureIOSViewport(doc, device)
  configureIOSViewport(doc, device)
  assert.equal(doc.viewport.content, `${original}, maximum-scale=1.0`)
  assert.doesNotThrow(() => configureIOSViewport({ querySelector: () => null }, device))
})
