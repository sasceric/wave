<script setup>
import { computed, watch } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { paginationItems } from '../../lib/pagination'

const props = defineProps({
  page: { type: Number, required: true },
  pageSize: { type: Number, required: true },
  total: { type: Number, required: true },
  pageSizes: { type: Array, default: () => [30, 60, 90] },
  showSinglePage: { type: Boolean, default: false },
  showPageSize: { type: Boolean, default: true },
  statusLabel: { type: String, default: '' },
})

const emit = defineEmits(['update:page', 'update:pageSize'])
const { t } = useI18n()

const pageCount = computed(() => Math.ceil(props.total / props.pageSize))
const pageItems = computed(() => paginationItems(props.page, pageCount.value))

watch(pageCount, (count) => {
  if (props.page > count && count > 0) {
    emit('update:page', count)
  }
})

function changePageSize(event) {
  emit('update:pageSize', Number(event.target.value))
  emit('update:page', 1)
}
</script>

<template>
  <div v-if="total > 0" class="directory-pagination">
    <label v-if="showPageSize" class="directory-pagination__page-size">
      <span>{{ t('directoryPagination.itemsPerPage') }}</span>
      <select :value="pageSize" :aria-label="t('directoryPagination.itemsPerPage')" @change="changePageSize">
        <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
      </select>
    </label>
    <p class="directory-pagination__status">
      {{ statusLabel || t('directoryPagination.pageStatus', { current: page, total: pageCount, count: total }) }}
    </p>
    <nav v-if="pageCount > 1 || showSinglePage" class="directory-pagination__controls" :aria-label="t('directoryPagination.ariaLabel')">
      <button
        type="button"
        :aria-label="t('directoryPagination.previous')"
        :title="t('directoryPagination.previous')"
        :disabled="page <= 1"
        @click="emit('update:page', page - 1)"
      >
        <ChevronLeft :size="17" aria-hidden="true" />
      </button>
      <div class="directory-pagination__pages">
        <template v-for="item in pageItems" :key="item.key">
          <span v-if="item.page === null" class="directory-pagination__ellipsis" aria-hidden="true">…</span>
          <button
            v-else
            type="button"
            :aria-label="t('directoryPagination.goToPage', { page: item.page })"
            :aria-current="page === item.page ? 'page' : undefined"
            :disabled="page === item.page"
            @click="emit('update:page', item.page)"
          >
            {{ item.page }}
          </button>
        </template>
      </div>
      <button
        type="button"
        :aria-label="t('directoryPagination.next')"
        :title="t('directoryPagination.next')"
        :disabled="page >= pageCount"
        @click="emit('update:page', page + 1)"
      >
        <ChevronRight :size="17" aria-hidden="true" />
      </button>
    </nav>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/DirectoryPagination.scss"></style>
