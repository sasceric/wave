export function paginationItems(current, count) {
  const total = Math.max(1, Math.floor(count))
  const page = Math.min(total, Math.max(1, current))
  const numbers = total <= 7
    ? Array.from({ length: total }, (_, index) => index + 1)
    : [...new Set([1, 2, 3, page - 1, page, page + 1, total - 2, total - 1, total])]
      .filter((number) => number >= 1 && number <= total)
      .sort((left, right) => left - right)
  const items = []
  numbers.forEach((number, index) => {
    const previous = numbers[index - 1]
    if (previous && number - previous > 1) items.push({ key: `gap-${previous}`, page: null })
    items.push({ key: `page-${number}`, page: number })
  })
  return items
}
