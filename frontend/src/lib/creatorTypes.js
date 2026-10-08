import creatorTypes from '../../../config/creator_types.json'

export function creatorTypeOptions(t) {
  return creatorTypes.map((value) => ({ value, label: t(`creatorTypes.options.${value}`) }))
}
