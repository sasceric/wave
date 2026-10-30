// Admin → E-mail predlošci: how each automatic email is grouped, who receives it and how often.
// Labels live in the locale files under emailTemplates.groups / recipients / frequency.
export const EMAIL_TEMPLATE_GROUPS = ['account', 'collaboration', 'profile', 'credits', 'contact']

export const EMAIL_TEMPLATE_CATALOG = {
  verify: { group: 'account', recipient: 'newUser', frequency: 'perRequest' },
  verification_reminder: { group: 'account', recipient: 'newUser', frequency: 'manual' },
  pre_approval: { group: 'account', recipient: 'newUser', frequency: 'manual' },
  reset: { group: 'account', recipient: 'user', frequency: 'perRequest' },
  registered: { group: 'account', recipient: 'newUser', frequency: 'once' },
  approval: { group: 'account', recipient: 'creator', frequency: 'once' },
  approval_company: { group: 'account', recipient: 'company', frequency: 'once' },
  account_deleted: { group: 'account', recipient: 'formerUser', frequency: 'once' },
  terms_updated: { group: 'account', recipient: 'allUsers', frequency: 'perVersion' },
  application_received: { group: 'collaboration', recipient: 'company', frequency: 'everyEvent' },
  campaign_invitation: { group: 'collaboration', recipient: 'creatorOrAgency', frequency: 'everyEvent' },
  creator_inquiry_received: { group: 'collaboration', recipient: 'creatorOrAgency', frequency: 'everyEvent' },
  creator_inquiry_accepted: { group: 'collaboration', recipient: 'company', frequency: 'everyEvent' },
  creator_hired: { group: 'collaboration', recipient: 'creatorOrAgency', frequency: 'everyEvent' },
  unread_message_reminder: { group: 'collaboration', recipient: 'participant', frequency: 'oncePerConversation' },
  campaign_alert: { group: 'collaboration', recipient: 'creator', frequency: 'dailyOrWeekly' },
  profile_tip: { group: 'profile', recipient: 'newUser', frequency: 'manual' },
  profile_reminder: { group: 'profile', recipient: 'creatorOrCompany', frequency: 'series' },
  credits_activated: { group: 'credits', recipient: 'allUsers', frequency: 'once' },
  credit_code_assigned: { group: 'credits', recipient: 'user', frequency: 'perRequest' },
  contact: { group: 'contact', recipient: 'team', frequency: 'everyEvent' },
  subscribed: { group: 'contact', recipient: 'subscriber', frequency: 'once' },
  admin_broadcast: { group: 'contact', recipient: 'allUsers', frequency: 'manual' },
}

export function templateMeta(key) {
  return EMAIL_TEMPLATE_CATALOG[key] ?? { group: 'account', recipient: 'user', frequency: 'everyEvent' }
}

/** Fill {{variables}} the same way the server does, escaping every value for HTML. */
export function renderTemplate(source, values, escape = true) {
  const result = String(source).replace(/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/g, (match, key) => {
    const value = values[key]
    if (value === undefined || value === null) return match
    return escape ? escapeHtml(String(value)) : String(value)
  })
  // Keep previewed headers consistent with the server's control-character removal.
  // eslint-disable-next-line no-control-regex
  return escape ? result : result.replace(/[\x00-\x1F\x7F]+/g, ' ').trim()
}

export function escapeHtml(value) {
  return value.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character])
}
