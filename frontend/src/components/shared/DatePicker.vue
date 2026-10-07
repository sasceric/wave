<script setup>
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { VueDatePicker } from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import { format } from 'date-fns'
import { bs, enUS, hr, sl, srLatn } from 'date-fns/locale'
import { parseDateOnly } from '../../lib/datePicker'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  min: { type: String, default: '' },
  max: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  helperText: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])
const { locale, t } = useI18n()
const id = `date-picker-${useId()}`
const dateLocale = computed(() => ({ bs, hr, sl, sr: srLatn, cnr: srLatn, en: enUS })[locale.value] || bs)
const yearRange = computed(() => [
  parseDateOnly(props.min)?.getFullYear() || 1900,
  parseDateOnly(props.max)?.getFullYear() || new Date().getFullYear() + 10,
])
const ariaLabels = computed(() => ({
  input: props.label,
  menu: t('datePicker.chooseDate'),
  calendarIcon: t('datePicker.chooseDate'),
  clearInput: t('datePicker.clear'),
  nextMonth: t('datePicker.nextMonth'),
  prevMonth: t('datePicker.previousMonth'),
  nextYear: t('datePicker.nextYear'),
  prevYear: t('datePicker.previousYear'),
  openYearsOverlay: t('datePicker.chooseYear'),
  openMonthsOverlay: t('datePicker.chooseMonth'),
  toggleOverlay: t('datePicker.toggleCalendar'),
  monthPicker: () => t('datePicker.chooseMonth'),
  yearPicker: () => t('datePicker.chooseYear'),
  day: ({ value }) => format(value, 'PPPP', { locale: dateLocale.value }),
}))

function updateDate(value) {
  if (props.disabled) return
  if (!value) return emit('update:modelValue', '')
  if (!parseDateOnly(value) || (props.min && value < props.min) || (props.max && value > props.max)) return
  emit('update:modelValue', value)
}
</script>

<template>
  <div
    class="form-field date-picker"
    data-validation-field
    data-validation-rule="date"
    :data-validation-value="modelValue"
    :data-required="required"
    :data-disabled="disabled"
    :data-min="min"
    :data-max="max"
  >
    <label :for="id">{{ label }}</label>
    <VueDatePicker
      :model-value="modelValue || null"
      model-type="yyyy-MM-dd"
      :formats="{ input: 'dd.MM.yyyy', month: 'LLLL' }"
      :locale="dateLocale"
      :aria-labels="ariaLabels"
      :input-attrs="{ id, required, clearable: !required, autocomplete: 'off' }"
      :time-config="{ enableTimePicker: false }"
      :min-date="min || undefined"
      :max-date="max || undefined"
      :year-range="yearRange"
      :disabled="disabled"
      :placeholder="placeholder || t('datePicker.placeholder')"
      :week-start="1"
      :config="{ monthChangeOnScroll: false }"
      :floating="{ arrow: false, offset: 6 }"
      :action-row="{ selectBtnLabel: t('datePicker.select'), cancelBtnLabel: t('datePicker.close') }"
      auto-apply
      reverse-years
      prevent-min-max-navigation
      @update:model-value="updateDate"
    />
    <small v-if="helperText">{{ helperText }}</small>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/DatePicker.scss"></style>
