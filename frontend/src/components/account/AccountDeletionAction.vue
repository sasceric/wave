<script setup>
import { ref } from 'vue'
import { Trash2 } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest } from '../../lib/api'
import { setCurrentUser } from '../../composables/useCurrentUser'
import { localizedPath } from '../../routePaths'

const emit = defineEmits(['opened'])
const { t, locale } = useI18n()
const router = useRouter()
const open = ref(false)
const deleting = ref(false)
const error = ref('')

function showConfirmation() {
  error.value = ''
  open.value = true
  emit('opened')
}

async function deleteAccount() {
  if (deleting.value) return
  deleting.value = true
  error.value = ''
  try {
    await apiRequest('/me/account', { method: 'DELETE', body: { confirmed: true } })
    open.value = false
    setCurrentUser(null)
    await router.replace({ path: localizedPath('account', locale.value), query: { mode: 'login', deleted: '1' } })
  } catch (cause) {
    error.value = cause.message
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <button class="account-sidebar__link account-sidebar__link--signout" type="button" @click="showConfirmation">
    <Trash2 :size="20" aria-hidden="true" /><span class="account-sidebar__text">{{ t('accountDeletion.action') }}</span>
  </button>
  <Teleport to="body">
    <ConfirmationModal v-model:open="open" :title="t('accountDeletion.title')" :message="t('accountDeletion.intro')" :confirm-label="t('accountDeletion.action')" :cancel-label="t('adminDashboard.cancelAction')" :loading="deleting" @confirm="deleteAccount">
      <ul class="account-deletion-details">
        <li>{{ t('accountDeletion.profile') }}</li>
        <li>{{ t('accountDeletion.history') }}</li>
        <li>{{ t('accountDeletion.session') }}</li>
      </ul>
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    </ConfirmationModal>
  </Teleport>
</template>

<style scoped lang="scss">
.account-deletion-details {
  margin: 14px 0 0;
  padding-left: 18px;
  color: var(--ink);
  font-size: 12px;
  line-height: 1.6;

  li + li { margin-top: 8px; }
}
</style>
