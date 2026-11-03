import { apiRequest } from './api'

// One place for sending a package request, shared by the creator profile and the /usluge sheet.
export function sendCreatorInquiry(slug, { packageIds = [], other = false, message }) {
  return apiRequest(`/creators/${encodeURIComponent(slug)}/inquiries`, {
    method: 'POST',
    body: { packageIds, servicePackage: false, other, message },
  })
}
