<script setup>
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Array, default: () => [0, 10000] },
  min: { type: Number, default: 0 },
  max: { type: Number, default: 30000 },
  step: { type: Number, default: 100 },
  label: { type: String, required: true },
  fromLabel: { type: String, required: true },
  toLabel: { type: String, required: true },
  currency: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { locale } = useI18n()
const id = `range-${useId()}`
const lower = computed(() => Math.max(props.min, Math.min(props.max, Number(props.modelValue[0]))))
const upper = computed(() => Math.max(lower.value, Math.min(props.max, Number(props.modelValue[1]))))
const trackStyle = computed(() => ({
  left: `${(lower.value - props.min) / (props.max - props.min) * 100}%`,
  width: `${(upper.value - lower.value) / (props.max - props.min) * 100}%`,
}))
function format(value) {
  const language = locale.value === 'cnr' || locale.value === 'sr' ? 'sr-Latn' : locale.value
  return new Intl.NumberFormat(language, props.currency ? { style: 'currency', currency: props.currency, maximumFractionDigits: 0 } : {}).format(value)
}
function updateLower(event) {
  if (props.disabled) return
  const value = Math.max(props.min, Math.min(upper.value, Number(event.target.value)))
  event.target.value = String(value)
  emit('update:modelValue', [value, upper.value])
}
function updateUpper(event) {
  if (props.disabled) return
  const value = Math.max(lower.value, Math.min(props.max, Number(event.target.value)))
  event.target.value = String(value)
  emit('update:modelValue', [lower.value, value])
}
</script>

<template>
  <div class="range-slider" role="group" :aria-label="label" :class="{ 'is-disabled': disabled }">
    <div class="range-slider__values">
      <label :for="`${id}-from`">{{ fromLabel }}<output :for="`${id}-from`">{{ format(lower) }}</output></label>
      <label :for="`${id}-to`">{{ toLabel }}<output :for="`${id}-to`">{{ format(upper) }}</output></label>
    </div>
    <div class="range-slider__track">
      <span class="range-slider__selection" :style="trackStyle" aria-hidden="true"></span>
      <input :id="`${id}-from`" class="range-slider__input range-slider__input--lower" :class="{ 'is-at-end': lower === max }" type="range"
        :value="lower" :min="min" :max="max" :step="step" :disabled="disabled"
        :aria-label="`${label}: ${fromLabel}`" :aria-valuetext="format(lower)" @input="updateLower" />
      <input :id="`${id}-to`" class="range-slider__input" type="range"
        :value="upper" :min="min" :max="max" :step="step" :disabled="disabled"
        :aria-label="`${label}: ${toLabel}`" :aria-valuetext="format(upper)" @input="updateUpper" />
    </div>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/RangeSlider.scss"></style>
