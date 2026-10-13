<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  total: { type: Number, default: null },
  rows: { type: Number, default: 0 },
  page: { type: Number, default: 1 },
  hasMore: { type: Boolean, default: false },
})
const { t } = useI18n()
const knownTotal = computed(() => props.total !== null || props.page === 1 && !props.hasMore)
const count = computed(() => props.total ?? props.rows)
</script>

<template>
  <span class="admin-table__results">{{ t(knownTotal ? 'adminDashboard.totalResults' : 'adminDashboard.loadedResults', { count }) }}</span>
</template>
