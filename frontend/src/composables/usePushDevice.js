import { ref } from 'vue'

// Push state for this device, owned by App.vue (which registers the toggle) and
// shown on the settings page and in the notifications panel.
export const pushSupported = ref(false)
export const pushSubscribed = ref(false)
export const pushStatus = ref('')
export const pushBusy = ref(false)
export const pushConfigured = ref(false)
export const pushPermission = ref(globalThis.Notification?.permission || 'default')

let toggleHandler = null

export function registerPushToggle(handler) {
  toggleHandler = handler
  return () => { if (toggleHandler === handler) toggleHandler = null }
}

export async function togglePushDevice() {
  if (toggleHandler) await toggleHandler()
}
