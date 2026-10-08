import { createI18n } from 'vue-i18n'
import bs from './locales/bs.json'

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
const catalogs = {
  bs: () => Promise.resolve(bs),
  hr: () => import('./locales/hr.json').then((module) => module.default),
  sr: () => import('./locales/sr.json').then((module) => module.default),
  sl: () => import('./locales/sl.json').then((module) => module.default),
  en: () => import('./locales/en.json').then((module) => module.default),
  cnr: () => import('./locales/cnr.json').then((module) => module.default),
}
const pendingCatalogs = new Map()
let localeRequest = 0

const i18n = createI18n({
  legacy: false,
  locale: 'bs',
  fallbackLocale: 'bs',
  messages: { bs },
})

export async function setLocale(locale) {
  if (!supportedLocales.includes(locale)) return
  const request = ++localeRequest
  if (!i18n.global.availableLocales.includes(locale)) {
    if (!pendingCatalogs.has(locale)) pendingCatalogs.set(locale, catalogs[locale]())
    try {
      i18n.global.setLocaleMessage(locale, await pendingCatalogs.get(locale))
    } finally {
      pendingCatalogs.delete(locale)
    }
  }
  if (request !== localeRequest) return
  i18n.global.locale.value = locale
  window.localStorage.setItem('wave-locale', locale)
  document.documentElement.lang = locale === 'cnr'
    ? 'sr-Latn-ME'
    : locale === 'sr'
      ? 'sr-Latn'
      : locale
  document.title = i18n.global.t('meta.title')
  document.querySelector('meta[name="description"]')?.setAttribute('content', i18n.global.t('meta.description'))
}

export const initialLocaleReady = setLocale(initialLocale)

export default i18n
