<script setup>
import AdminTableFrame from './AdminTableFrame.vue'
import SkeletonBlock from '../shared/SkeletonBlock.vue'
import { computed } from 'vue'
import AdminPagination from './AdminPagination.vue'

const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, required: true },
  labels: { type: Object, required: true },
  page: { type: Number, default: 1 },
  pageSize: { type: Number, default: 25 },
  pageSizes: { type: Array, default: () => [25, 50, 100] },
  hasMore: { type: Boolean, default: false },
  total: { type: Number, default: null },
  availablePages: { type: Number, default: 1 },
  busy: { type: Boolean, default: false },
  showEmpty: { type: Boolean, default: true },
})
defineEmits(['page-size', 'page'])
const pageCount = computed(() => props.total === null
  ? Math.max(props.availablePages, props.page + Number(props.hasMore))
  : Math.max(1, Math.ceil(props.total / props.pageSize)))
</script>

<template>
  <AdminTableFrame :busy="busy">
    <template #toolbar><slot name="toolbar" /></template>
    <div class="admin-table__scroll" tabindex="0" role="region" :aria-label="labels.caption">
      <table class="admin-table__table">
        <caption class="sr-only">{{ labels.caption }}</caption>
        <thead><tr><th v-for="column in columns" :key="column.key" scope="col" :class="{ 'admin-table__actions': column.key === 'actions' }">{{ column.label }}</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td v-for="column in columns" :key="column.key" :class="{ 'admin-table__actions': column.key === 'actions' }">
              <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{ row[column.key] ?? '—' }}</slot>
            </td>
          </tr>
          <tr v-for="index in busy && !rows.length ? 5 : 0" :key="`loading-${index}`" aria-hidden="true">
            <td v-for="column in columns" :key="column.key" :class="{ 'admin-table__actions': column.key === 'actions' }"><SkeletonBlock width="80%" /></td>
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
      <AdminPagination :page="page" :count="pageCount" :busy="busy" :labels="labels" @change="$emit('page', $event)" />
    </footer>
  </AdminTableFrame>
</template>
