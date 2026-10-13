// One account row per campaign. Applications own the collaboration and offer;
// invitations fill in campaigns which do not have an application yet.
export function mergeCampaignActivity(applications, invitations, offers) {
  const rows = new Map()
  for (const application of applications) {
    rows.set(application.campaign.id ?? application.campaign.slug, { ...application, source: 'application' })
  }
  for (const offer of offers) {
    const key = offer.campaign.id ?? offer.campaign.slug
    const application = rows.get(key)
    rows.set(key, {
      ...(application || { id: offer.applicationId, status: offer.status, createdAt: offer.createdAt, campaign: offer.campaign, readOnly: offer.readOnly }),
      source: 'application', offer,
    })
  }
  for (const invitation of invitations) {
    const key = invitation.campaign.id ?? invitation.campaign.slug
    const application = rows.get(key)
    if (application) {
      application.invitation = invitation
      application.conversationId ??= invitation.conversationId
    } else {
      rows.set(key, { ...invitation, id: `invitation-${invitation.id}`, source: 'invitation', invitation })
    }
  }
  return [...rows.values()].sort((a, b) => new Date(b.offer?.createdAt || b.createdAt) - new Date(a.offer?.createdAt || a.createdAt))
}

export function campaignActivityStage(item) {
  const offer = item.offer
  if (item.campaign?.status === 'cancelled' || item.status === 'cancelled' || offer?.status === 'cancelled') return 'cancelled'
  if (offer?.status === 'accepted' || (item.source !== 'invitation' && item.status === 'accepted')) {
    return item.campaign?.status === 'finished' ? 'finished' : 'hired'
  }
  if (['rejected', 'offer_declined', 'declined'].includes(item.status) || offer?.status === 'rejected') return 'rejected'
  if (['closed', 'finished'].includes(item.campaign?.status)) return 'closed'
  if (offer?.status === 'pending') return 'offered'
  if (item.source === 'invitation') {
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    if (item.status === 'pending' && new Date(item.campaign.closesAt).getTime() < today.getTime()) return 'closed'
    return 'invited'
  }
  if (item.status === 'shortlisted') return 'discussing'
  return 'applied'
}
