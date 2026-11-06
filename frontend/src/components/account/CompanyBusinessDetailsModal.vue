<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormModal from '../shared/FormModal.vue'
import { apiRequest } from '../../lib/api'
import { currentUser, setCurrentUser } from '../../composables/useCurrentUser'

// Company ID, VAT number and owner name. Private: only the account owner and admins see them.
// Used from Moj profil (company details card) and Postavke → Vrsta računa.
const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['update:open', 'saved'])
const { t } = useI18n()
const busy = ref(false)
const details = ref({ registrationId: '', vatId: '', ownerFullName: '' })

watch(() => props.open, value => {
  if (!value) return
  const values = currentUser.value?.profile?.businessDetails || {}
  details.value = { registrationId: values.registrationId || '', vatId: values.vatId || '', ownerFullName: values.ownerFullName || '' }
}, { immediate: true })

async function save() {
  if (busy.value) return
  const id = currentUser.value?.id
  busy.value = true
  try {
    const { data } = await apiRequest('/me/company-details', { method: 'PUT', body: { ...details.value } })
    if (currentUser.value?.id === id) setCurrentUser(data)
    emit('saved', data?.profile?.businessDetails || { ...details.value })
    emit('update:open', false)
  } catch { /* Shared action feedback shows the server error. */ }
  finally { busy.value = false }
}
</script>

<template>
  <FormModal v-if="open" :open="open" :title="t('accountType.businessDetails')" :description="t('accountType.privateHint')" :busy="busy" @update:open="emit('update:open', $event)" @submit="save">
    <label><span>{{ t('accountType.registrationId') }}</span><input v-model.trim="details.registrationId" maxlength="60" :disabled="busy" /></label>
    <label><span>{{ t('accountType.vatId') }}</span><input v-model.trim="details.vatId" maxlength="60" :disabled="busy" /></label>
    <label><span>{{ t('accountType.ownerFullName') }}</span><input v-model.trim="details.ownerFullName" maxlength="120" autocomplete="name" :disabled="busy" /></label>
    <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('listingAds.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('accountType.save') }}</button></template>
  </FormModal>
</template>
