<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronDown } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LanguageFlag from './LanguageFlag.vue'
import { localeNames } from '../../i18n'
import { defaultLocale, localizedRouteName, localizedRouteNames } from '../../routePaths'

defineProps({ expanded: { type: Boolean, default: false } })

const { locale, t } = useI18n()
const route = useRoute()
const router = useRouter()
const menu = ref(null)
const isOpen = ref(false)
const languages = Object.entries(localeNames)

function closeOnOutsideClick(event) {
  if (menu.value && event.target instanceof Node && !menu.value.contains(event.target)) {
    isOpen.value = false
  }
}

function selectLocale(code) {
  locale.value = code
  isOpen.value = false

  if (localizedRouteNames.has(route.meta.routeName)) {
    router.replace({
      name: localizedRouteName(route.meta.routeName, code),
      params: route.params,
      query: route.query,
      hash: route.hash,
    })
    return
  }

  const pathMatch = Array.isArray(route.params.pathMatch)
    ? route.params.pathMatch.join('/')
    : route.params.pathMatch
  const path = pathMatch
    ? (code === defaultLocale ? `/${pathMatch}` : `/${code}/${pathMatch}`)
    : (code === defaultLocale ? '/' : `/${code}/`)
  router.replace({
    path,
    query: route.query,
    hash: route.hash,
  })
}

onMounted(() => document.addEventListener('pointerdown', closeOnOutsideClick))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutsideClick))
</script>

<template>
  <div
    ref="menu"
    class="language-switcher"
    :class="{ 'language-switcher--expanded': expanded }"
    @keydown.esc.stop.prevent="isOpen = false"
  >
    <button
      class="language-switcher__trigger"
      type="button"
      :aria-label="t('app.language')"
      :aria-expanded="isOpen"
      aria-haspopup="menu"
      @click="isOpen = !isOpen"
    >
      <LanguageFlag :locale="locale" />
      <span v-if="expanded" class="language-switcher__name">{{ localeNames[locale] }}</span>
      <ChevronDown v-if="expanded" :size="16" aria-hidden="true" />
    </button>
    <div v-if="isOpen" class="language-switcher__menu" role="menu" :aria-label="t('app.language')">
      <button
        v-for="[code, name] in languages"
        :key="code"
        class="language-switcher__option"
        :class="{ 'is-selected': locale === code }"
        type="button"
        role="menuitemradio"
        :aria-checked="locale === code"
        @click="selectLocale(code)"
      >
        <LanguageFlag :locale="code" />
        <span>{{ name }}</span>
        <span v-if="locale === code" class="language-switcher__check" aria-hidden="true">✓</span>
      </button>
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/LanguageSwitcher.scss"></style>
