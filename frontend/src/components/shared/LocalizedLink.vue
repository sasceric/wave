<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  localizedPath,
  localizedRouteName,
  localizedRouteNames,
  routeNameFromCanonicalPath,
} from '../../routePaths'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  to: { type: [String, Object], required: true },
})

const { locale } = useI18n()

const localizedTo = computed(() => {
  if (typeof props.to === 'string') {
    const name = routeNameFromCanonicalPath(props.to)
    return name ? localizedPath(name, locale.value) : props.to
  }

  if (localizedRouteNames.has(props.to.name)) {
    return {
      ...props.to,
      name: localizedRouteName(props.to.name, locale.value),
    }
  }

  return props.to
})
</script>

<template>
  <RouterLink v-bind="$attrs" :to="localizedTo">
    <slot />
  </RouterLink>
</template>
