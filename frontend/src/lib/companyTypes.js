import companyTypes from '../../../config/company_types.json'

export function companyTypeOptions(t) {
  return companyTypes.map(value => ({ value, label: t(`companyTypes.options.${value}`) }))
}
