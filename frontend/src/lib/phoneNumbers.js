import { parsePhoneNumberFromString } from 'libphonenumber-js/min'

export function formatInternationalPhoneNumber(value, countryCode) {
  const phoneNumber = parsePhoneNumberFromString(value.trim(), countryCode)

  return phoneNumber?.isPossible() ? phoneNumber.number : null
}
