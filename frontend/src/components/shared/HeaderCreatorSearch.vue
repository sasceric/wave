<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { ArrowRight, MapPin, Coins, Search, X } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiRequest, formatMoney } from '../../lib/api'
import { localizedRouteName } from '../../routePaths'
import CardImage from './CardImage.vue'
import LocalizedLink from './LocalizedLink.vue'
import SingleSelect from './SingleSelect.vue'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const types = ['creators', 'campaigns', 'companies']
const type = ref(types.includes(route.meta.routeName) ? route.meta.routeName : 'creators')
const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const expanded = ref(false)
const mobile = ref(false)
const open = ref(false)
const loading = ref(false)
const error = ref(false)
const results = ref([])
const counts = ref({})
const input = ref(null)
const form = ref(null)
const panel = ref(null)
const panelTop = ref(90)
const backdropTop = ref(82)
const panelId = `header-search-${useId()}`
const options = computed(() => types.map(value => ({ value, label: t(`globalSearch.${value}`) })))
let mediaQuery
let timer
let requestId = 0
let lastLogged = ''
let restoringFocus = false

function positionPanel() {
  if (!form.value) return
  const formBottom = form.value.getBoundingClientRect().bottom
  backdropTop.value = form.value.closest('.site-header')?.getBoundingClientRect().bottom ?? formBottom
  panelTop.value = Math.max(formBottom, backdropTop.value) + 8
}
function updateViewport() {
  mobile.value = mediaQuery.matches
  if (!mobile.value) expanded.value = false
  nextTick(positionPanel)
}
function closeSearch(restoreFocus = false) {
  open.value = false
  expanded.value = false
  window.clearTimeout(timer)
  requestId++
  loading.value = false
  if (restoreFocus) nextTick(() => {
    restoringFocus = true
    const target = mobile.value ? form.value?.querySelector('.header-creator-search__submit') : input.value
    target?.focus()
    restoringFocus = false
  })
}
function handleInputFocus() {
  if (!restoringFocus && query.value.trim()) scheduleSearch()
}
async function openSearch() {
  if (mobile.value) expanded.value = true
  await nextTick()
  positionPanel()
  input.value?.focus()
  if (query.value.trim()) scheduleSearch()
}
function scheduleSearch() {
  window.clearTimeout(timer)
  requestId++
  results.value = []
  error.value = false
  loading.value = Boolean(query.value.trim())
  open.value = loading.value
  nextTick(positionPanel)
  if (open.value) timer = window.setTimeout(loadSuggestions, 250)
}
async function loadSuggestions() {
  window.clearTimeout(timer)
  const term = query.value.trim()
  if (!term) {
    open.value = false
    loading.value = false
    return
  }
  const id = ++requestId
  const selected = type.value
  const language = locale.value
  open.value = true
  loading.value = true
  error.value = false
  positionPanel()
  try {
    const params = new URLSearchParams({ q: term, limit: '5' })
    if (selected !== 'creators') params.set('view', 'card')
    const response = await apiGet(`/${selected}?${params}`, { locale: language })
    if (id !== requestId) return
    results.value = response.data ?? []
    counts.value = { ...counts.value, [selected]: response.meta?.total ?? results.value.length }
  } catch {
    if (id === requestId) error.value = true
  } finally {
    if (id === requestId) loading.value = false
  }
}
function recordSearch(source) {
  const term = query.value.trim()
  const key = `${locale.value}:${type.value}:${term}`
  if (!term || key === lastLogged) return
  lastLogged = key
  // A log failure must never interrupt searching or directory navigation.
  apiRequest('/search/history', { method: 'POST', locale: locale.value, body: { query: term, type: type.value, source } })
    .catch(() => { if (lastLogged === key) lastLogged = '' })
}
async function handleSubmit() {
  if (mobile.value && !expanded.value) return openSearch()
  if (!query.value.trim()) return seeAll()
  recordSearch('submit')
  await loadSuggestions()
}
function seeAll() {
  const term = query.value.trim()
  recordSearch('all')
  router.push({ name: localizedRouteName(type.value, locale.value), query: term ? { q: term } : {} })
  closeSearch()
}
function itemRoute(item) {
  const names = { creators: 'creator-profile', companies: 'company-profile', campaigns: 'campaign-detail' }
  return { name: names[type.value], params: { slug: item.slug } }
}
function image(item) { return type.value === 'creators' ? item.avatarImage : type.value === 'companies' ? item.logoImage : item.coverImage }
function imageUrl(item) { return type.value === 'creators' ? item.avatarUrl : type.value === 'companies' ? item.logoUrl : item.coverImageUrl }
function title(item) { return type.value === 'creators' ? item.displayName : type.value === 'companies' ? item.name : item.title }
function subtitle(item) { return type.value === 'creators' ? (item.categoryLabels ?? [item.categoryLabel]).filter(Boolean).join(', ') : type.value === 'companies' ? (item.industryLabels ?? [item.industry]).filter(Boolean).join(', ') : item.company?.name }
function focusResult() { panel.value?.querySelector('a')?.focus() }
function handleKey(event) { if (event.key === 'Escape' && (open.value || expanded.value)) { event.preventDefault(); closeSearch(true) } }

watch([query, locale], () => {
  counts.value = {}
  lastLogged = ''
  scheduleSearch()
})
watch(type, () => { lastLogged = ''; scheduleSearch() })
watch(() => route.fullPath, () => {
  closeSearch()
  const nextType = route.meta.routeName
  if (types.includes(nextType)) type.value = nextType
  query.value = typeof route.query.q === 'string' ? route.query.q : ''
  nextTick(() => closeSearch())
})
onMounted(() => {
  mediaQuery = window.matchMedia('(max-width: 900px)')
  updateViewport()
  mediaQuery.addEventListener('change', updateViewport)
  window.addEventListener('resize', positionPanel)
  document.addEventListener('keydown', handleKey)
})
onBeforeUnmount(() => {
  requestId++
  window.clearTimeout(timer)
  mediaQuery?.removeEventListener('change', updateViewport)
  window.removeEventListener('resize', positionPanel)
  document.removeEventListener('keydown', handleKey)
})
</script>

<template>
  <form ref="form" class="header-creator-search" :class="{ 'is-expanded': expanded, 'is-open': open }" role="search" @submit.prevent="handleSubmit">
    <label class="header-creator-search__field">
      <Search :size="17" aria-hidden="true" />
      <input ref="input" v-model="query" type="search" maxlength="200" :placeholder="t('globalSearch.placeholder')" :aria-label="t('globalSearch.placeholder')" :aria-controls="open ? panelId : undefined" @focus="handleInputFocus" @keydown.down.prevent="focusResult" />
    </label>
    <SingleSelect v-model="type" class="header-creator-search__type" :options="options" :label="t('globalSearch.type')" :show-label="false" />
    <button class="header-creator-search__submit" type="submit" :aria-label="t('globalSearch.search')" :aria-expanded="open" :aria-controls="open ? panelId : undefined"><Search :size="19" aria-hidden="true" /></button>
    <button v-if="mobile && expanded" class="header-creator-search__close" type="button" :aria-label="t('app.closeSearch')" @click="closeSearch(true)"><X :size="18" aria-hidden="true" /></button>
  </form>
  <Teleport to="body">
    <template v-if="open">
      <div class="global-search-backdrop" :style="{ top: `${backdropTop}px` }" @click="closeSearch()" />
      <section :id="panelId" ref="panel" class="global-search-panel" :style="{ top: `${panelTop}px` }" :aria-label="t('globalSearch.results')" :aria-busy="loading">
        <div class="global-search-panel__tabs">
          <button v-for="option in options" :key="option.value" type="button" :class="{ 'is-active': type === option.value }" :aria-pressed="type === option.value" @click="type = option.value">{{ option.label }}<span v-if="counts[option.value] !== undefined"> ({{ counts[option.value] }})</span></button>
          <button class="global-search-panel__close" type="button" :aria-label="t('app.closeSearch')" @click="closeSearch(true)"><X :size="18" /></button>
        </div>
        <div class="global-search-panel__rows" aria-live="polite">
          <p v-if="loading" class="global-search-panel__message">{{ t('globalSearch.loading') }}</p>
          <p v-else-if="error" class="global-search-panel__message">{{ t('globalSearch.error') }} <button type="button" @click="loadSuggestions">{{ t('globalSearch.retry') }}</button></p>
          <p v-else-if="!results.length" class="global-search-panel__message">{{ t('globalSearch.empty') }}</p>
          <LocalizedLink v-for="item in loading || error ? [] : results" :key="item.id" class="global-search-panel__row" :to="itemRoute(item)" @click="recordSearch('all'); closeSearch()">
            <CardImage :image="image(item)" :src="imageUrl(item) || ''" :alt="title(item)" sizes="76px" />
            <span class="global-search-panel__identity"><strong>{{ title(item) }}</strong><span>{{ subtitle(item) }}</span></span>
            <span v-if="item.city" class="global-search-panel__location"><MapPin :size="16" aria-hidden="true" />{{ item.city }}</span>
            <span v-if="type === 'campaigns'" class="global-search-panel__budget"><Coins :size="18" aria-hidden="true" />{{ formatMoney(item.budgetMin, item.currency) }} – {{ formatMoney(item.budgetMax, item.currency) }}</span>
          </LocalizedLink>
        </div>
        <button class="global-search-panel__all" type="button" @click="seeAll"><Search :size="19" aria-hidden="true" /><span>{{ t('globalSearch.seeAll', { query: query.trim() }) }}</span><ArrowRight :size="19" aria-hidden="true" /></button>
      </section>
    </template>
  </Teleport>
</template>

<style lang="scss" src="../../scss/components/shared/HeaderCreatorSearch.scss"></style>
