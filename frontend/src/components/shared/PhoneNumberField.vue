<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { getCountries, getCountryCallingCode } from 'libphonenumber-js/min'
import SearchableSelect from './SearchableSelect.vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  countryCode: { type: String, default: 'BA' },
  label: { type: String, required: true },
  placeholder: { type: String, required: true },
  countryLabel: { type: String, required: true },
  countryPlaceholder: { type: String, required: true },
  countrySearchPlaceholder: { type: String, required: true },
  noCountriesFoundLabel: { type: String, required: true },
  optional: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:countryCode'])
const { locale } = useI18n()
const countryDisplayLocale = computed(() => {
  if (locale.value === 'cnr') return 'bs'
  if (locale.value === 'sr') return 'sr-Latn'
  return locale.value
})

const countryOptions = computed(() => {
  const countryNames = new Intl.DisplayNames([countryDisplayLocale.value], { type: 'region' })

  return getCountries()
    .map((code) => {
      const countryName = countryNames.of(code) || code

      return {
        value: code,
        countryName,
        label: `${flagEmoji(code)} +${getCountryCallingCode(code)} · ${countryName}`,
      }
    })
    .sort((first, second) => first.countryName.localeCompare(second.countryName, countryDisplayLocale.value))
})

function flagEmoji(countryCode) {
  return countryCode
    .split('')
    .map((letter) => String.fromCodePoint(letter.charCodeAt(0) + 127397))
    .join('')
}

function updatePhone(event) {
  emit('update:modelValue', event.target.value)
}
</script>

<template>
  <div class="phone-number-field">
    <SearchableSelect
      class="phone-number-field__country"
      :model-value="props.countryCode"
      :options="countryOptions"
      :label="countryLabel"
      :placeholder="countryPlaceholder"
      :search-placeholder="countrySearchPlaceholder"
      :no-results-label="noCountriesFoundLabel"
      @update:model-value="emit('update:countryCode', $event)"
    />
    <label class="form-field phone-number-field__number">
      <span>{{ label }}</span>
      <input
        :value="props.modelValue"
        type="tel"
        inputmode="tel"
        :required="!optional"
        maxlength="40"
        autocomplete="tel-national"
        :placeholder="placeholder"
        @input="updatePhone"
      />
    </label>
  </div>
</template>

<style lang="scss" src="../../scss/components/shared/PhoneNumberField.scss"></style>
