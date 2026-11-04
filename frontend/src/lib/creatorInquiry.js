import { apiRequest } from './api'

// One place for sending a package request, shared by the creator profile and the /usluge sheet.
// `source` says where the request was sent from, for measuring the services page.
export function sendCreatorInquiry(slug, { packageIds = [], other = false, message, source = 'profile' }) {
  return apiRequest(`/creators/${encodeURIComponent(slug)}/inquiries`, {
    method: 'POST',
    body: { packageIds, servicePackage: false, other, message, source },
  })
}
