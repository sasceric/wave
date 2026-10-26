<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { Check, Search, X } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { apiGet } from '../../lib/api'

// Creators for one campaign, twenty at a time while scrolling. "All" selects every
// creator who has not been invited yet, including ones that are not loaded.
const props = defineProps({
  campaignId: { type: Number, required: true },
  modelValue: { type: Array, default: () => [] },
  all: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'update:all', 'available'])
const { t, locale } = useI18n()
const id = useId()
const list = ref(null)
const sentinel = ref(null)
const query = ref('')
const rows = ref([])
const cursor = ref(null)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')
const total = ref(null)
const available = ref(null)
const failedImages = ref(new Set())
let revision = 0
let timer
let observer
let disposed = false

const selected = computed(() => new Set(props.modelValue))
const selectedCount = computed(() => (props.all ? available.value ?? 0 : props.modelValue.length))

async function load(reset = false) {
  if (disposed || (loading.value && !reset)) return
  const current = reset ? ++revision : revision
  if (reset) { rows.value = []; cursor.value = null; hasMore.value = false }
  loading.value = true
  error.value = ''
  const params = new URLSearchParams({ limit: '20' })
  if (query.value.trim()) params.set('q', query.value.trim())
  if (cursor.value) params.set('cursor', cursor.value)
  try {
    const { data } = await apiGet(`/admin/campaign-invitations/${props.campaignId}/creators?${params}`)
    if (disposed || current !== revision) return
    rows.value = [...new Map([...rows.value, ...data.rows].map((row) => [row.id, row])).values()]
    cursor.value = data.nextCursor
    hasMore.value = data.hasMore
    if (data.total !== null) total.value = data.total
    if (data.available !== null && !query.value.trim()) {
      available.value = data.available
      emit('available', data.available)
    }
  } catch (cause) {
    if (!disposed && current === revision) error.value = cause.message || t('adminInvitations.loadError')
  } finally {
    if (!disposed && current === revision) {
      loading.value = false
      nextTick(fillViewport)
    }
  }
}

// Keep loading while the sentinel is still visible (short lists, tall screens).
function fillViewport() {
  if (!hasMore.value || loading.value || !list.value || !sentinel.value) return
  if (sentinel.value.offsetTop - list.value.scrollTop <= list.value.clientHeight + 80) load()
}

function scrolled() {
  fillViewport()
}

function toggle(row) {
  if (props.disabled || props.all || row.status) return
  const next = new Set(props.modelValue)
  if (next.has(row.id)) next.delete(row.id)
  else next.add(row.id)
  emit('update:modelValue', [...next])
}

function toggleAll() {
  if (props.disabled) return
  emit('update:all', !props.all)
  if (!props.all) emit('update:modelValue', [])
}

function clear() {
  emit('update:all', false)
  emit('update:modelValue', [])
}

function strengthTone(value) {
  if (value === null || value === undefined) return 'none'
  if (value >= 80) return 'high'
  if (value >= 50) return 'mid'
  return 'low'
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase()
}

function isChecked(row) {
  return !row.status && (props.all || selected.value.has(row.id))
}

/** Reload statuses after invitations were sent. */
function refresh() {
  load(true)
}
defineExpose({ refresh })

watch(query, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(true), 250)
})
watch(() => [props.campaignId, locale.value], () => {
  total.value = null
  available.value = null
  emit('update:modelValue', [])
  emit('update:all', false)
  load(true)
})
onMounted(() => {
  load(true)
  if (typeof IntersectionObserver === 'function') {
    observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting) && hasMore.value) load()
    }, { root: list.value, rootMargin: '120px' })
    if (sentinel.value) observer.observe(sentinel.value)
  }
})
onBeforeUnmount(() => {
  disposed = true
  ++revision
  clearTimeout(timer)
  observer?.disconnect()
})
</script>

<template>
  <div class="creator-picker" :aria-busy="loading">
    <div class="creator-picker__toolbar">
      <label class="creator-picker__search">
        <Search :size="16" aria-hidden="true" />
        <input v-model="query" type="search" :placeholder="t('adminInvitations.searchCreators')" :aria-label="t('adminInvitations.searchCreators')" :disabled="disabled" />
      </label>
      <button type="button" class="creator-picker__all" :class="{ 'is-active': all }" :aria-pressed="all" :disabled="disabled || !available" @click="toggleAll">
        <span class="creator-picker__check" aria-hidden="true"><Check v-if="all" :size="13" /></span>
        {{ t('adminInvitations.selectAll', { count: available ?? 0 }) }}
      </button>
    </div>
    <div class="creator-picker__summary">
      <span>{{ t('adminInvitations.selectedCount', { count: selectedCount }) }}<template v-if="total !== null"> · {{ t('adminInvitations.totalCreators', { count: total }) }}</template></span>
      <button v-if="selectedCount" type="button" class="creator-picker__clear" :disabled="disabled" @click="clear"><X :size="14" aria-hidden="true" />{{ t('adminInvitations.clearSelection') }}</button>
    </div>
    <ul :id="`${id}-list`" ref="list" class="creator-picker__list" role="listbox" aria-multiselectable="true" :aria-label="t('adminInvitations.creators')" @scroll.passive="scrolled">
      <li
        v-for="row in rows"
        :key="row.id"
        class="creator-picker__row"
        :class="{ 'is-checked': isChecked(row), 'is-taken': row.status }"
        role="option"
        :aria-selected="isChecked(row)"
        :aria-disabled="Boolean(row.status || all || disabled)"
        tabindex="0"
        @click="toggle(row)"
        @keydown.enter.prevent="toggle(row)"
        @keydown.space.prevent="toggle(row)"
      >
        <span class="creator-picker__check" aria-hidden="true"><Check v-if="isChecked(row)" :size="13" /></span>
        <span class="creator-picker__avatar">
          <img v-if="row.avatarUrl && !failedImages.has(row.id)" :src="row.avatarUrl" alt="" width="36" height="36" loading="lazy" decoding="async" @error="failedImages.add(row.id)" />
          <span v-else aria-hidden="true">{{ initials(row.name) }}</span>
        </span>
        <span class="creator-picker__name">
          <span class="creator-picker__title">
            <strong>{{ row.name }}</strong>
            <span class="creator-picker__strength" :class="`is-${strengthTone(row.profileStrength)}`" :title="t('adminInvitations.profileStrength')" :aria-label="`${t('adminInvitations.profileStrength')}: ${row.profileStrength ?? '–'}%`">
              {{ row.profileStrength === null ? '–' : `${row.profileStrength}%` }}
            </span>
          </span>
          <small v-if="row.city">{{ row.city }}</small>
        </span>
        <span v-if="row.status" class="creator-picker__status">{{ t(`adminInvitations.status.${row.status}`) }}</span>
      </li>
      <li v-if="loading" class="creator-picker__note" role="status">{{ t('adminDashboard.loading') }}</li>
      <li v-else-if="!rows.length && !error" class="creator-picker__note">{{ t('adminInvitations.noCreators') }}</li>
      <li v-if="error" class="creator-picker__note" role="alert">{{ error }} <button type="button" class="text-link" @click="load(!rows.length)">{{ t('reviews.retry') }}</button></li>
      <li ref="sentinel" class="creator-picker__sentinel" aria-hidden="true"></li>
    </ul>
  </div>
</template>

<style lang="scss" src="../../scss/components/admin/AdminCreatorPicker.scss"></style>
