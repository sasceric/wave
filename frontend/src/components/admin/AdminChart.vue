<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusMessage from '../shared/StatusMessage.vue'
import { createAdminChart } from '../../lib/adminCharts'

const props = defineProps({ type: { type: String, required: true }, data: { type: Object, required: true }, label: { type: String, required: true }, height: { type: Number, default: 230 }, options: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const canvas = ref(null)
const error = ref(false)
let chart = null
let disposed = false
let generation = 0
// Chart.js receives plain configuration, never Vue proxies. Resolve CSS variables for Canvas colors.
function configValue(value) {
  if (typeof value === 'string' && value.startsWith('var(')) return getComputedStyle(document.documentElement).getPropertyValue(value.slice(4, -1)).trim() || '#61736a'
  if (Array.isArray(value)) return value.map(configValue)
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, configValue(item)]))
  return value
}
async function render() {
  const current = ++generation
  error.value = false
  try {
    await nextTick()
    if (disposed || current !== generation || !canvas.value) return
    chart?.destroy()
    chart = createAdminChart(canvas.value, props.type, configValue(props.data), configValue(props.options))
  } catch { if (!disposed && current === generation) error.value = true }
}
onMounted(render)
watch(() => [props.type, props.data, props.options], render, { deep: true })
onBeforeUnmount(() => { disposed = true; ++generation; chart?.destroy() })
</script>
<template>
  <div class="admin-chart" :style="{ height: `${height}px` }">
    <StatusMessage v-if="error" variant="error">{{ t('adminCollaborations.chartError') }}</StatusMessage>
    <canvas ref="canvas" role="img" :aria-label="label">{{ label }}</canvas>
  </div>
</template>
<style scoped>
.admin-chart { position: relative; width: 100%; min-width: 0; }
</style>
