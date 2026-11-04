import { formatMoney } from './api'

export function isVoluntary(campaign) {
  return campaign?.compensationType === 'voluntary'
}

export function isPaidInProducts(campaign) {
  return campaign?.compensationType === 'products'
}

export function campaignCompensationLabel(campaign, t, amount = null) {
  if (isVoluntary(campaign)) return t('compensation.noFee')
  const value = amount !== null
    ? formatMoney(amount, campaign?.currency)
    : `${formatMoney(campaign?.budgetMin, campaign?.currency)} – ${formatMoney(campaign?.budgetMax, campaign?.currency)}`
  // Product compensation shows the products' value, so creators never read it as money.
  return isPaidInProducts(campaign) ? t('compensation.productsValue', { value }) : value
}
