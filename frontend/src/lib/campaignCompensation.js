import { formatMoney } from './api'

export function isVoluntary(campaign) {
  return campaign?.compensationType === 'voluntary'
}

export function campaignCompensationLabel(campaign, t, amount = null) {
  if (isVoluntary(campaign)) return t('compensation.noFee')
  if (amount !== null) return formatMoney(amount, campaign?.currency)
  return `${formatMoney(campaign?.budgetMin, campaign?.currency)} – ${formatMoney(campaign?.budgetMax, campaign?.currency)}`
}
