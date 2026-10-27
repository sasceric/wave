<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { currentUser } from '../../composables/useCurrentUser'
import { loadMaintenance, maintenanceState } from '../../composables/useMaintenance'
import { apiRequest } from '../../lib/api'
import LocalizedLink from '../shared/LocalizedLink.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import SwitchField from '../shared/SwitchField.vue'

const { t } = useI18n()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
onMounted(async () => {
  try { await loadMaintenance() }
  catch (cause) { error.value = cause.message }
  finally { loading.value = false }
})
async function update(value) {
  if (!currentUser.value?.isAdmin || saving.value || loading.value) return
  saving.value = true
  error.value = ''
  try {
    const { data } = await apiRequest('/admin/maintenance', {
      method: 'PUT', body: { enabled: value === 1 },
      feedback: { success: t(value === 1 ? 'maintenance.enabledNotice' : 'maintenance.disabledNotice') },
    })
    maintenanceState.value = data
  } catch (cause) { error.value = cause.message }
  finally { saving.value = false }
}
</script>

<template>
  <section v-if="currentUser?.isAdmin" class="account-settings__group" aria-labelledby="settings-maintenance">
    <h2 id="settings-maintenance">{{ t('maintenance.settingsTitle') }}</h2>
    <div class="account-settings__card">
      <SwitchField :model-value="maintenanceState?.enabled ? 1 : 0" :label="t('maintenance.toggle')" :description="t('maintenance.settingsHint')" :disabled="loading || saving" @update:model-value="update" />
      <LocalizedLink class="account-settings__row" :to="{ name: 'maintenance' }"><span class="account-settings__text">{{ t('maintenance.preview') }}</span></LocalizedLink>
    </div>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
  </section>
</template>
