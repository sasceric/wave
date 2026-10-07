<script setup>
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { paginationItems } from '../../lib/pagination'

const props = defineProps({
  page: { type: Number, default: 1 },
  count: { type: Number, default: 1 },
  busy: { type: Boolean, default: false },
  labels: { type: Object, required: true },
})
defineEmits(['change'])
const { t } = useI18n()
const items = computed(() => paginationItems(props.page, props.count))
</script>

<template>
  <nav class="admin-table__pagination-controls" :aria-label="labels.pagination">
    <button class="admin-table__page-button" type="button" :disabled="busy || page <= 1" :aria-label="labels.previous" @click="$emit('change', page - 1)">
      <ChevronLeft :size="16" aria-hidden="true" />
    </button>
    <div class="admin-table__page-numbers">
      <template v-for="item in items" :key="item.key">
        <span v-if="item.page === null" class="admin-table__ellipsis" aria-hidden="true">…</span>
        <button v-else class="admin-table__page-number" type="button"
          :aria-label="t('adminDashboard.goToPage', { page: item.page })"
          :aria-current="page === item.page ? 'page' : undefined"
          :disabled="busy || page === item.page" @click="$emit('change', item.page)">
          {{ item.page }}
        </button>
      </template>
    </div>
    <button class="admin-table__page-button" type="button" :disabled="busy || page >= count" :aria-label="labels.next" @click="$emit('change', page + 1)">
      <ChevronRight :size="16" aria-hidden="true" />
    </button>
  </nav>
</template>
