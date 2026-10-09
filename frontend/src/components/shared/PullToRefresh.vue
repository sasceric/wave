<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, RefreshCw } from '@lucide/vue'
import { isInstalledApp, startPullToRefresh } from '../../lib/pullToRefresh'

const { t } = useI18n()
const progress = ref(0)
const refreshing = ref(false)
const installed = window.matchMedia('(display-mode: standalone)')
let stop = null

function configure() {
  if (isInstalledApp(window) && !stop) {
    stop = startPullToRefresh({
      onChange(value, busy) {
        progress.value = value
        refreshing.value = busy
      },
      async onRefresh() {
        // Paint the busy indicator before navigation starts.
        await new Promise(resolve => window.requestAnimationFrame(() => window.requestAnimationFrame(resolve)))
        window.location.reload()
      },
    })
  } else if (!isInstalledApp(window) && stop) {
    stop()
    stop = null
  }
}

onMounted(() => {
  configure()
  installed.addEventListener('change', configure)
})
onBeforeUnmount(() => {
  installed.removeEventListener('change', configure)
  stop?.()
})
</script>

<template>
  <div
    v-if="progress > 0 || refreshing"
    class="pull-to-refresh"
    :class="{ 'pull-to-refresh--busy': refreshing }"
    :style="{ '--pull-offset': `${Math.min(progress, 1.5) * 38}px` }"
    role="status"
    aria-live="polite"
  >
    <RefreshCw v-if="refreshing" :size="18" aria-hidden="true" />
    <ArrowDown v-else :size="18" :style="{ transform: `rotate(${progress >= 1 ? 180 : 0}deg)` }" aria-hidden="true" />
    <span>{{ t(refreshing ? 'app.pullRefreshing' : progress >= 1 ? 'app.pullRelease' : 'app.pullRefresh') }}</span>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/PullToRefresh.scss"></style>
