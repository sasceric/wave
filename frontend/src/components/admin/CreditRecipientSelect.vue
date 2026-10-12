<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { ChevronDown, Search } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { apiGet } from '../../lib/api'

const props = defineProps({ accountType: { type: String, required: true }, modelValue: { type: Number, default: null }, disabled: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])
const { t, locale } = useI18n()
const id = useId()
const root = ref(null)
const trigger = ref(null)
const searchField = ref(null)
const isOpen = ref(false)
const query = ref('')
const rows = ref([])
const selected = ref(null)
const cursor = ref(null)
const hasMore = ref(false)
const loading = ref(false)
const error = ref('')
const label = computed(() => t('credits.selectRecipient'))
let revision = 0
let timer
let disposed = false

async function load(reset = false) {
  if (disposed || !props.accountType || loading.value && !reset) return
  const current = reset ? ++revision : revision
  if (reset) { rows.value = []; cursor.value = null; hasMore.value = false }
  loading.value = true
  error.value = ''
  const params = new URLSearchParams({ accountType: props.accountType, q: query.value.trim(), limit: '20' })
  if (cursor.value) params.set('cursor', cursor.value)
  try {
    const { data } = await apiGet(`/admin/credits/recipients?${params}`)
    if (disposed || current !== revision) return
    rows.value = [...new Map([...rows.value, ...data.rows].map(row => [row.id, row])).values()]
    cursor.value = data.nextCursor
    hasMore.value = data.hasMore
  } catch (cause) {
    if (!disposed && current === revision) error.value = cause.message
  } finally {
    if (!disposed && current === revision) loading.value = false
  }
}
function scrolled(event) {
  const list = event.target
  if (hasMore.value && list.scrollTop + list.clientHeight >= list.scrollHeight - 24) load()
}
async function open() {
  if (props.disabled) return
  isOpen.value = true
  if (!rows.value.length && !loading.value) await load(true)
  await nextTick()
  if (!disposed && isOpen.value) searchField.value?.focus({ preventScroll: true })
}
function close(restore = false) {
  isOpen.value = false
  if (restore) nextTick(() => trigger.value?.focus({ preventScroll: true }))
}
function choose(row) {
  selected.value = row
  emit('update:modelValue', row.id)
  close(true)
}
function focusOption(index) {
  document.getElementById(`${id}-option-${index}`)?.focus({ preventScroll: true })
  const element = document.getElementById(`${id}-option-${index}`)
  element?.scrollIntoView({ block: 'nearest' })
}
function move(index, step) {
  const next = index + step
  if (next >= 0 && next < rows.value.length) focusOption(next)
  else if (step > 0 && hasMore.value) load().then(() => nextTick(() => focusOption(next)))
}
function escape(event) {
  if (!isOpen.value) return
  event.preventDefault()
  event.stopPropagation()
  close(true)
}
watch(query, () => {
  clearTimeout(timer)
  ++revision
  rows.value = []
  cursor.value = null
  hasMore.value = false
  error.value = ''
  loading.value = true
  timer = setTimeout(() => load(true), 250)
})
watch(() => [props.accountType, locale.value], () => {
  clearTimeout(timer)
  ++revision
  rows.value = []
  selected.value = null
  cursor.value = null
  hasMore.value = false
  loading.value = false
  error.value = ''
  query.value = ''
  emit('update:modelValue', null)
  close()
})
watch(() => props.modelValue, value => { if (value === null) selected.value = null })
onBeforeUnmount(() => { disposed = true; ++revision; clearTimeout(timer) })
</script>

<template>
  <div ref="root" class="form-field single-select credit-recipient-select" @focusout="!root?.contains($event.relatedTarget) && close()" @keydown.esc="escape">
    <span :id="`${id}-label`">{{ label }}</span>
    <button ref="trigger" type="button" class="single-select__trigger" :aria-labelledby="`${id}-label ${id}-value`" aria-haspopup="listbox" :aria-expanded="isOpen" :aria-controls="`${id}-options`" :disabled="disabled" @click="isOpen ? close() : open()" @keydown.down.prevent="open()">
      <span :id="`${id}-value`">{{ selected?.name || t('credits.chooseAccount') }}</span><ChevronDown :size="16" aria-hidden="true" />
    </button>
    <div v-if="isOpen" class="single-select__menu credit-recipient-select__menu">
      <label class="credit-recipient-select__search"><Search :size="16" aria-hidden="true" /><input ref="searchField" v-model="query" type="search" :aria-label="t('credits.searchRecipients')" :placeholder="t('credits.searchRecipients')" @keydown.down.prevent="focusOption(0)" /></label>
      <div :id="`${id}-options`" class="credit-recipient-select__options" role="listbox" :aria-labelledby="`${id}-label`" :aria-busy="loading" @scroll="scrolled">
        <button v-for="(row, index) in rows" :id="`${id}-option-${index}`" :key="row.id" type="button" role="option" class="single-select__option" :aria-selected="modelValue === row.id" @click="choose(row)" @keydown.down.prevent="move(index, 1)" @keydown.up.prevent="move(index, -1)" @keydown.home.prevent="focusOption(0)" @keydown.end.prevent="focusOption(rows.length - 1)">
          <span><strong>{{ row.name }}</strong><small>{{ row.email }}</small></span>
        </button>
        <p v-if="loading" role="status">{{ t('adminDashboard.loading') }}</p>
        <p v-else-if="!rows.length && !error">{{ t('credits.noRecipients') }}</p>
        <p v-if="error" role="alert">{{ error }} <button type="button" @click="load(!rows.length)">{{ t('reviews.retry') }}</button></p>
      </div>
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/SingleSelect.scss"></style>
<style scoped lang="scss">
.credit-recipient-select {
  &__menu { position: static; width: 100%; max-width: none; max-height: none; margin-top: 5px; padding: 8px; box-sizing: border-box; }
  &__search { display: flex !important; align-items: center; gap: 8px; padding: 4px 8px; border-bottom: 1px solid #dfe4dc; input { min-width: 0; border: 0 !important; padding: 8px 0 !important; outline: none; background: transparent !important; } }
  &__options { max-height: 240px; overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; }
  .single-select__option { display: block; width: 100%; white-space: normal; text-align: left; padding: 10px; &[aria-selected="true"] { background: #eef1eb; } }
  strong, small { display: block; overflow-wrap: anywhere; }
  strong { font-weight: 500; font-size: 13px; }
  small { margin-top: 3px; color: var(--muted); font-size: 12px; }
  p { padding: 10px; margin: 0; font-size: 12px; color: var(--muted); }
}
</style>
