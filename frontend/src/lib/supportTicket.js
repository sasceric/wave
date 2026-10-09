export const ticketKinds = ['problem', 'question', 'suggestion']
export const ticketCategories = ['account', 'campaigns', 'services', 'messages', 'technical', 'other']
export const ticketMaxBytes = 10 * 1024 * 1024
const allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'video/mp4', 'application/pdf']

export function validTicketFiles(files) {
  return files.length <= 3 && files.every(file => file.size > 0 && file.size <= ticketMaxBytes && allowedTypes.includes(file.type))
}

export function ticketSubmissionKey() {
  return Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2, '0')).join('')
}
