<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { getCountries, getCountryCallingCode, parsePhoneNumberFromString } from 'libphonenumber-js/min'
import { nationalPhoneNumber } from '../../lib/phoneNumbers'
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

const displayedPhone = computed(() => nationalPhoneNumber(props.modelValue, props.countryCode))

function updateCountry(countryCode) {
  const phone = displayedPhone.value
  emit('update:countryCode', countryCode)
  emit('update:modelValue', phone)
}

function updatePhone(event) {
  const value = event.target.value
  const country = value.trim().startsWith('+') ? parsePhoneNumberFromString(value)?.country : null
  if (country && country !== props.countryCode) emit('update:countryCode', country)
  const phone = nationalPhoneNumber(value, country || props.countryCode)
  event.target.value = phone
  emit('update:modelValue', phone)
}
</script>

<template>
  <div class="phone-number-field">
    <SearchableSelect
      :required="!optional"
      class="phone-number-field__country"
      :model-value="props.countryCode"
      :options="countryOptions"
      :label="countryLabel"
      :placeholder="countryPlaceholder"
      :search-placeholder="countrySearchPlaceholder"
      :no-results-label="noCountriesFoundLabel"
      @update:model-value="updateCountry"
    />
    <label class="form-field phone-number-field__number">
      <span>{{ label }}</span>
      <input
        data-validation-rule="phone"
        :data-phone-country="countryCode"
        :value="displayedPhone"
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
