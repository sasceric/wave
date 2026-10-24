import { formatMoney } from './api'

// Lowest priced package in the currency of the first priced package, formatted for display.
export function startingPackagePrice(packages = []) {
  const priced = packages.filter(({ price }) => Number(price) > 0)
  if (!priced.length) {
    return ''
  }
  const currency = priced[0].currency
  const cheapest = priced
    .filter((packageItem) => packageItem.currency === currency)
    .reduce((lowest, packageItem) => (Number(packageItem.price) < Number(lowest.price) ? packageItem : lowest))

  return formatMoney(cheapest.price, cheapest.currency)
}

export function formatAverageRating(average, locale) {
  if (average == null) {
    return '—'
  }
  const numberLocale = ['bs', 'cnr'].includes(locale) ? 'hr' : locale

  return new Intl.NumberFormat(numberLocale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(average))
}
