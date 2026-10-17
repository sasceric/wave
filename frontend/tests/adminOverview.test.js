import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ref, nextTick } from 'vue'
import * as charts from '../src/lib/overviewCharts.js'
import { setupView } from './setupView.js'

test('overview uses bounded periods and drops stale responses when changing period or account', async () => {
  const currentUser = ref({ id: 1, isAdmin: true })
  const pending = []
  const { state } = await setupView('../src/components/admin/AdminOverviewAnalytics.vue', {
    '../../composables/useCurrentUser': { currentUser, loadCurrentUser: async () => {} },
    '../../lib/overviewCharts': charts,
    '../../lib/api': { apiGet: path => new Promise(resolve => pending.push({ path, resolve })), formatDate: value => value },
  })
  assert.equal(pending[0].path, '/admin/overview?days=30&accountType=all')
  state.days.value = 7
  await nextTick()
  assert.equal(pending[1].path, '/admin/overview?days=7&accountType=all')
  pending[0].resolve({ userId: 1, data: { days: 30 } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.statistics.value, null)
  pending[1].resolve({ userId: 1, data: { days: 7, series: [{ creators: 2, campaigns: 0 }] } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.statistics.value.days, 7)
  assert.equal(state.maximum.value, 4)
  state.statistics.value.profileCompletion = { total: 5, networks: 2, works: 3, packages: 1, complete: 1 }
  assert.deepEqual(Array.from(state.profileCompletionRows.value, row => [row.key, row.count, row.rate]), [
    ['networks', 2, 40], ['works', 3, 60], ['packages', 1, 20], ['complete', 1, 20],
  ])
  state.statistics.value.profileCompletion = { total: 0 }
  assert.equal(state.profileCompletionRows.value.every(row => row.count === 0 && row.rate === 0), true)
  state.statistics.value.agencyProfileCompletion = { total: 4, services: 2, works: 1, packages: 3, complete: 1 }
  assert.deepEqual(Array.from(state.agencyProfileCompletionRows.value, row => [row.key, row.count, row.rate]), [
    ['services', 2, 50], ['works', 1, 25], ['packages', 3, 75], ['complete', 1, 25],
  ])
  assert.equal(state.completionSections.value[0].total, 0)
  assert.equal(state.completionSections.value[1].total, 4)
  state.statistics.value.agencyProfileCompletion = { total: 0 }
  assert.equal(state.agencyProfileCompletionRows.value.every(row => row.count === 0 && row.rate === 0), true)
  assert.deepEqual(Array.from(state.activationTypes.value, option => option.value), ['all', 'creator', 'company', 'brand', 'agency'])
  state.accountType.value = 'agency'
  await nextTick()
  assert.equal(pending.at(-1).path, '/admin/overview?days=7&accountType=agency')
  state.days.value = 90
  await nextTick()
  currentUser.value = { id: 2, isAdmin: false }
  await nextTick()
  pending.at(-1).resolve({ userId: 1, data: { days: 90 } })
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(state.statistics.value, null)
  assert.equal(state.loading.value, false)
})


test('overview time buckets preserve actual totals and keep long periods legible', () => {
  const rows = Array.from({ length: 90 }, (_, index) => ({ date: String(index), creators: 1, campaigns: index % 2, applications: index, invitations: 2 }))
  const buckets = charts.bucketOverviewSeries(rows)
  assert.ok(buckets.length <= 8)
  assert.equal(buckets[0].from, '0')
  assert.equal(buckets.at(-1).to, '89')
  assert.equal(buckets.reduce((sum, row) => sum + row.applications, 0), rows.reduce((sum, row) => sum + row.applications, 0))
  assert.equal(buckets.reduce((sum, row) => sum + row.invitations, 0), 180)
  const growth = charts.cumulativeOverviewSeries(rows)
  assert.equal(growth.at(-1).creators, 90)
  assert.equal(growth.at(-1).campaigns, 45)
  assert.equal(rows.at(-1).creators, 1)
  assert.equal(charts.overviewCurve([], 'creators', 4), '')
  assert.equal(charts.overviewScale(0), 4)
})
