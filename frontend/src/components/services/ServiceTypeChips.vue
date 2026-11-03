<script setup>
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import { SERVICE_PAGE_SLUGS, serviceTypeLocation } from '../../lib/serviceTypes'

// Filter chips for package types; an empty value means all types.
defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, required: true },
  counts: { type: Object, default: null },
  // Render types that have their own page as real links (crawlable), keeping these query filters.
  links: { type: Boolean, default: false },
  linkQuery: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
</script>

<template>
  <div class="service-chips" role="group" :aria-label="t('services.typeLabel')">
    <template v-for="option in options" :key="option || 'all'">
      <LocalizedLink
        v-if="links && (!option || SERVICE_PAGE_SLUGS[option])"
        class="service-chips__chip"
        :to="serviceTypeLocation(option, linkQuery)"
        :aria-current="modelValue === option ? 'page' : undefined"
      >
        {{ option ? t(`services.types.${option}`) : t('services.allTypes') }}
        <span v-if="counts && option" class="service-chips__count">{{ counts[option] ?? 0 }}</span>
      </LocalizedLink>
      <button
        v-else
        class="service-chips__chip"
        type="button"
        :aria-pressed="modelValue === option"
        @click="emit('update:modelValue', option)"
      >
        {{ option ? t(`services.types.${option}`) : t('services.allTypes') }}
        <span v-if="counts && option" class="service-chips__count">{{ counts[option] ?? 0 }}</span>
      </button>
    </template>
  </div>
</template>

<style scoped lang="scss" src="../../scss/components/services/ServiceTypeChips.scss"></style>
