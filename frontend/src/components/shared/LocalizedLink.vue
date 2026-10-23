<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  localizedPath,
  localizedRouteName,
  localizedRouteNames,
  routeNameFromCanonicalPath,
  authenticationDestination,
} from '../../routePaths'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  to: { type: [String, Object], required: true },
})

const { locale } = useI18n()

const localizedTo = computed(() => {
  const to = authenticationDestination(props.to)
  if (typeof to === 'string') {
    const name = routeNameFromCanonicalPath(to)
    return name ? localizedPath(name, locale.value) : to
  }

  if (localizedRouteNames.has(to.name)) {
    return {
      ...to,
      name: localizedRouteName(to.name, locale.value),
    }
  }

  return to
})
</script>

<template>
  <RouterLink v-bind="$attrs" :to="localizedTo">
    <slot />
  </RouterLink>
</template>
