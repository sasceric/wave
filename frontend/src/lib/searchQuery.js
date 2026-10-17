export const SEARCH_MIN_LENGTH = 3

export function isSearchableQuery(value) {
  return Array.from(value.trim()).length >= SEARCH_MIN_LENGTH
}
