import { getCountryCallingCode, parsePhoneNumberFromString } from 'libphonenumber-js/min'

export function nationalPhoneNumber(value, countryCode) {
  const text = String(value || '').trim()
  if (!text.startsWith('+')) return text
  const number = parsePhoneNumberFromString(text)
  if (number?.country === countryCode) return number.nationalNumber
  const prefix = `+${getCountryCallingCode(countryCode)}`
  return text.startsWith(prefix) ? text.slice(prefix.length).replace(/^[\s-]+/, '') : text
}

export function formatInternationalPhoneNumber(value, countryCode) {
  const phoneNumber = parsePhoneNumberFromString(value.trim(), countryCode)

  return phoneNumber?.isPossible() ? phoneNumber.number : null
}
