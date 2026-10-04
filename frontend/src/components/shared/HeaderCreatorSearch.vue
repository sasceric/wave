<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Search, X } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { localizedRouteName } from '../../routePaths'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const expanded = ref(false)
const mobile = ref(false)
const input = ref(null)
let mediaQuery

function updateViewport() {
  mobile.value = mediaQuery.matches
  if (!mobile.value) expanded.value = false
}

function searchCreators() {
  const search = query.value.trim()
  router.push({
    name: localizedRouteName('creators', locale.value),
    query: search ? { q: search } : {},
  })
  expanded.value = false
}

function openSearch() {
  expanded.value = true
  nextTick(() => input.value?.focus())
}

function handleSubmit(event) {
  if (mobile.value && !expanded.value) {
    event.preventDefault()
    openSearch()
    return
  }
  searchCreators()
}

watch(() => route.query.q, (value) => {
  query.value = typeof value === 'string' ? value : ''
})

onMounted(() => {
  mediaQuery = window.matchMedia('(max-width: 900px)')
  updateViewport()
  mediaQuery.addEventListener('change', updateViewport)
})

onBeforeUnmount(() => mediaQuery?.removeEventListener('change', updateViewport))
</script>

<template>
  <form
    class="header-creator-search"
    :class="{ 'is-expanded': expanded }"
    role="search"
    @submit.prevent="handleSubmit"
  >
    <label class="header-creator-search__field">
      <Search :size="17" aria-hidden="true" />
      <input
        ref="input"
        v-model="query"
        type="search"
        :placeholder="t('home.creatorSearchPlaceholder')"
        :aria-label="t('creatorsPage.searchLabel')"
      />
    </label>
    <button
      class="header-creator-search__submit"
      type="submit"
      :aria-label="t('home.searchCreators')"
    >
      <Search :size="17" aria-hidden="true" />
    </button>
    <button
      v-if="mobile && expanded"
      class="header-creator-search__close"
      type="button"
      :aria-label="t('app.closeSearch')"
      @click="expanded = false"
    >
      <X :size="18" aria-hidden="true" />
    </button>
  </form>
</template>
