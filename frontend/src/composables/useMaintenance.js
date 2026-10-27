import { shallowRef } from 'vue'
import { apiGet } from '../lib/api'

export const maintenanceState = shallowRef(null)
let pending = null

export async function loadMaintenance() {
  if (pending) return pending
  pending = apiGet('/maintenance').then(({ data }) => {
    maintenanceState.value = data
    return data
  })
  try { return await pending }
  finally { pending = null }
}

export function maintenanceDestination(route, state) {
  const name = route.meta?.routeName
  if (name === 'maintenance') return state && !state.enabled && !state.bypass ? { name: 'home' } : null
  if (!state?.enabled || state.bypass || ['login', 'forgot-password', 'reset-password'].includes(name)) return null
  return { name: 'maintenance', replace: true }
}
