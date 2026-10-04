<script setup>
import { Search } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { useMarketplaceCatalog } from '../../composables/useMarketplaceCatalog'
import StatusMessage from './StatusMessage.vue'

defineProps({
  search: { type: String, required: true },
  category: { type: String, required: true },
  searchPlaceholder: { type: String, required: true },
  searchLabel: { type: String, required: true },
  categoryLabel: { type: String, required: true },
  countLabel: { type: String, required: true },
  platform: { type: String, default: '' },
  platformLabel: { type: String, default: '' },
  platformOptions: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:search', 'update:category', 'update:platform'])
const { t, locale } = useI18n()
const { categories, error } = useMarketplaceCatalog(locale)
</script>

<template>
  <div class="directory__filters">
    <label class="search-field">
      <Search :size="19" aria-hidden="true" />
      <input
        :value="search"
        type="search"
        :placeholder="searchPlaceholder"
        :aria-label="searchLabel"
        @input="emit('update:search', $event.target.value)"
      />
    </label>
    <label class="select-field">
      <span class="sr-only">{{ categoryLabel }}</span>
      <select
        :value="category"
        :aria-label="categoryLabel"
        @change="emit('update:category', $event.target.value)"
      >
        <option value="">{{ t('categories.all') }}</option>
        <option v-for="item in categories" :key="item.value" :value="item.value">
          {{ item.label }}
        </option>
      </select>
    </label>
    <label v-if="platformLabel" class="select-field">
      <span class="sr-only">{{ platformLabel }}</span>
      <select
        :value="platform"
        :aria-label="platformLabel"
        @change="emit('update:platform', $event.target.value)"
      >
        <option value="">{{ t('platforms.all') }}</option>
        <option v-for="item in platformOptions" :key="item" :value="item">
          {{ item }}
        </option>
      </select>
    </label>
    <span class="directory__count">{{ countLabel }}</span>
  </div>
  <StatusMessage v-if="error" variant="error" class="directory-catalog-error">{{ error }}</StatusMessage>
</template>
