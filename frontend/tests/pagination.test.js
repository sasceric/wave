import assert from 'node:assert/strict'
import { test } from 'node:test'
import { paginationItems } from '../src/lib/pagination.js'

test('pagination follows the supplied beginning and middle examples', () => {
  const pages = (current, total) => paginationItems(current, total).map(({ page }) => page)
  assert.deepEqual(pages(1, 37), [1, 2, 3, null, 35, 36, 37])
  assert.deepEqual(pages(2, 7787), [1, 2, 3, null, 7785, 7786, 7787])
  assert.deepEqual(pages(3, 7787), [1, 2, 3, 4, null, 7785, 7786, 7787])
  assert.deepEqual(pages(4, 7791), [1, null, 3, 4, 5, null, 7791])
  assert.deepEqual(pages(20, 37), [1, null, 19, 20, 21, null, 37])
  assert.deepEqual(pages(35, 37), [1, 2, 3, null, 34, 35, 36, 37])
  assert.deepEqual(pages(37, 37), [1, 2, 3, null, 35, 36, 37])
  assert.deepEqual(pages(4, 7), [1, 2, 3, 4, 5, 6, 7])
})

test('pagination remains bounded and includes the selected and last page', () => {
  for (const total of [1, 2, 7, 8, 37, 7791]) {
    for (const current of [1, 3, 4, total - 2, total]) {
      const items = paginationItems(current, total)
      const pages = items.filter(({ page }) => page !== null).map(({ page }) => page)
      assert.ok(pages.includes(Math.max(1, Math.min(current, total))))
      assert.equal(pages[0], 1)
      assert.equal(pages.at(-1), total)
      assert.equal(new Set(items.map(({ key }) => key)).size, items.length)
      assert.ok(items.length <= 9)
    }
  }
})
