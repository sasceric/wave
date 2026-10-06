<script setup>
import { ChevronLeft, ChevronRight } from '@lucide/vue'

defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  labels: { type: Object, required: true },
  page: { type: Number, default: 1 },
  pageSize: { type: Number, default: 25 },
  pageSizes: { type: Array, default: () => [25, 50, 100] },
  hasMore: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  showEmpty: { type: Boolean, default: true },
})
defineEmits(['page-size', 'previous', 'next'])
</script>

<template>
  <div class="admin-table" :aria-busy="busy">
    <div class="admin-table__header"><slot name="toolbar" /></div>
    <div class="admin-table__scroll">
      <table class="admin-table__table">
        <caption class="sr-only">{{ labels.caption }}</caption>
        <thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td v-for="column in columns" :key="column.key">
              <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ row[column.key] ?? '—' }}</slot>
            </td>
          </tr>
          <tr v-if="!rows.length && !busy && showEmpty"><td :colspan="columns.length" class="admin-table__empty">{{ labels.empty }}</td></tr>
        </tbody>
      </table>
    </div>
    <footer class="admin-table__pagination">
      <label class="admin-table__page-size">
        {{ labels.pageSize }}
        <select :value="pageSize" :aria-label="labels.pageSize" :disabled="busy" @change="$emit('page-size', Number($event.target.value))">
          <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
        </select>
      </label>
      <nav class="admin-table__pagination-controls" :aria-label="labels.pagination">
        <button class="admin-table__page-button" type="button" :disabled="busy || page <= 1" :aria-label="labels.previous" @click="$emit('previous')"><ChevronLeft :size="16" aria-hidden="true" /></button>
        <span aria-live="polite">{{ labels.page }}</span>
        <button class="admin-table__page-button" type="button" :disabled="busy || !hasMore" :aria-label="labels.next" @click="$emit('next')"><ChevronRight :size="16" aria-hidden="true" /></button>
      </nav>
    </footer>
  </div>
</template>
