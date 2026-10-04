import { createI18n } from 'vue-i18n'
import bs from './locales/bs.json'
import en from './locales/en.json'
import cnr from './locales/cnr.json'
import hr from './locales/hr.json'
import sl from './locales/sl.json'
import sr from './locales/sr.json'

export const localeNames = {
  bs: 'Bosanski',
  hr: 'Hrvatski',
  sr: 'Srpski',
  sl: 'Slovenščina',
  en: 'English',
  cnr: 'Crnogorski',
}

const supportedLocales = Object.keys(localeNames)
const savedLocale = window.localStorage.getItem('wave-locale')
const initialLocale = supportedLocales.includes(savedLocale) ? savedLocale : 'bs'

const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'bs',
  messages: { bs, hr, sr, sl, en, cnr },
})

export function setLocale(locale) {
  if (!supportedLocales.includes(locale)) return
  i18n.global.locale.value = locale
  window.localStorage.setItem('wave-locale', locale)
  document.documentElement.lang = locale === 'cnr'
    ? 'cnr-Latn-ME'
    : locale === 'sr'
      ? 'sr-Latn'
      : locale
  document.title = i18n.global.t('meta.title')
  document.querySelector('meta[name="description"]')?.setAttribute('content', i18n.global.t('meta.description'))
}

setLocale(initialLocale)

export default i18n
