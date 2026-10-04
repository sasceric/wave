<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, SearchX } from '@lucide/vue'

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  labels: { type: Object, required: true },
  pageSize: { type: Number, default: 10 },
})

const emit = defineEmits(['delete-selected'])
const selectedIds = ref([])
const page = ref(1)
const pageSize = ref(props.pageSize)
const sortKey = ref(props.columns.find(({ defaultSort }) => defaultSort)?.key ?? props.columns.find(({ sortable }) => sortable)?.key ?? '')
const sortDirection = ref('asc')

const sortedRows = computed(() => {
  if (!sortKey.value) return props.rows

  return [...props.rows].sort((left, right) => {
    const a = String(valueFor(left, sortKey.value) ?? '').toLocaleLowerCase()
    const b = String(valueFor(right, sortKey.value) ?? '').toLocaleLowerCase()
    return a.localeCompare(b, undefined, { numeric: true }) * (sortDirection.value === 'asc' ? 1 : -1)
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(sortedRows.value.length / pageSize.value)))
const visibleRows = computed(() => sortedRows.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
const visibleIds = computed(() => visibleRows.value.map(({ id }) => id))
const allVisibleSelected = computed(() => visibleIds.value.length > 0 && visibleIds.value.every((id) => selectedIds.value.includes(id)))
const paginationItems = computed(() => {
  const total = pageCount.value
  const numbers = total <= 7
    ? Array.from({ length: total }, (_, index) => index + 1)
    : [...new Set([1, page.value - 1, page.value, page.value + 1, total])]
      .filter((number) => number >= 1 && number <= total)
      .sort((left, right) => left - right)
  const items = []

  numbers.forEach((number, index) => {
    const previous = numbers[index - 1]
    if (previous && number - previous > 1) {
      items.push({ key: `ellipsis-${previous}-${number}`, page: null })
    }
    items.push({ key: `page-${number}`, page: number })
  })

  return items
})

watch(() => props.rows.map(({ id }) => id), (ids) => {
  selectedIds.value = selectedIds.value.filter((id) => ids.includes(id))
  page.value = 1
})

watch([pageCount, pageSize], ([count]) => {
  if (page.value > count) page.value = count
})

function valueFor(row, key) {
  return key.split('.').reduce((value, part) => value?.[part], row)
}

function toggleAll(event) {
  const visible = new Set(visibleIds.value)
  selectedIds.value = event.target.checked
    ? [...new Set([...selectedIds.value, ...visible])]
    : selectedIds.value.filter((id) => !visible.has(id))
}

function toggleRow(id, checked) {
  selectedIds.value = checked
    ? [...new Set([...selectedIds.value, id])]
    : selectedIds.value.filter((selectedId) => selectedId !== id)
}

function sortBy(column) {
  if (!column.sortable) return
  if (sortKey.value === column.key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = column.key
    sortDirection.value = 'asc'
  }
}
</script>

<template>
  <div class="admin-table">
    <div class="admin-table__header">
      <div class="admin-table__filters">
        <slot name="toolbar" />
      </div>
      <div class="admin-table__selection">
        <span>{{ labels.selected(selectedIds.length) }}</span>
        <slot name="bulk-actions" :ids="selectedIds" />
        <button
          v-if="selectedIds.length"
          class="button button--outline"
          type="button"
          @click="emit('delete-selected', [...selectedIds])"
        >
          {{ labels.deleteSelected }}
        </button>
      </div>
    </div>
    <div class="admin-table__scroll">
      <table class="admin-table__table">
        <thead>
          <tr>
            <th class="admin-table__check">
              <input type="checkbox" :checked="allVisibleSelected" :aria-label="labels.selectPage" @change="toggleAll" />
            </th>
            <th
              v-for="column in columns"
              :key="column.key"
              :aria-sort="sortKey === column.key ? (sortDirection === 'asc' ? 'ascending' : 'descending') : undefined"
            >
              <button v-if="column.sortable" type="button" class="admin-table__sort" @click="sortBy(column)">
                {{ column.label }} <span aria-hidden="true">{{ sortKey === column.key ? (sortDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
              </button>
              <span v-else>{{ column.label }}</span>
            </th>
            <th>{{ labels.actions }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibleRows" :key="row.id">
            <td class="admin-table__check">
              <input
                type="checkbox"
                :checked="selectedIds.includes(row.id)"
                :aria-label="labels.selectRow(row.name ?? row.displayName ?? row.title ?? row.email ?? String(row.id))"
                @change="toggleRow(row.id, $event.target.checked)"
              />
            </td>
            <td v-for="column in columns" :key="column.key">
              <slot :name="`cell-${column.key}`" :row="row" :value="valueFor(row, column.key)">
                {{ valueFor(row, column.key) ?? '—' }}
              </slot>
            </td>
            <td class="admin-table__actions"><slot name="actions" :row="row" /></td>
          </tr>
          <tr v-if="visibleRows.length === 0">
            <td class="admin-table__empty" :colspan="columns.length + 2">
              <div class="admin-table__empty-state">
                <span class="admin-table__empty-icon">
                  <SearchX :size="19" aria-hidden="true" />
                </span>
                <span>{{ labels.empty }}</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <footer class="admin-table__pagination">
      <label class="admin-table__page-size">
        {{ labels.pageSize }}
        <select v-model.number="pageSize" :aria-label="labels.pageSize">
          <option :value="10">10</option>
          <option :value="25">25</option>
          <option :value="50">50</option>
        </select>
      </label>
      <nav class="admin-table__pagination-controls" :aria-label="labels.pagination">
        <button
          class="admin-table__page-button"
          type="button"
          :aria-label="labels.previous"
          :title="labels.previous"
          :disabled="page <= 1"
          @click="page--"
        >
          <ChevronLeft :size="16" aria-hidden="true" />
        </button>
        <div class="admin-table__page-numbers">
          <template v-for="item in paginationItems" :key="item.key">
            <span v-if="item.page === null" class="admin-table__ellipsis" aria-hidden="true">…</span>
            <button
              v-else
              class="admin-table__page-number"
              type="button"
              :aria-label="labels.goToPage(item.page)"
              :aria-current="page === item.page ? 'page' : undefined"
              :disabled="page === item.page"
              @click="page = item.page"
            >
              {{ item.page }}
            </button>
          </template>
        </div>
        <button
          class="admin-table__page-button"
          type="button"
          :aria-label="labels.next"
          :title="labels.next"
          :disabled="page >= pageCount"
          @click="page++"
        >
          <ChevronRight :size="16" aria-hidden="true" />
        </button>
      </nav>
    </footer>
  </div>
</template>
