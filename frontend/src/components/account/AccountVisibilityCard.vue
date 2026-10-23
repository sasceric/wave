<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, ShieldCheck, UserRound } from '@lucide/vue'
import SwitchField from '../shared/SwitchField.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import { currentUser, setCurrentUser } from '../../composables/useCurrentUser'
import { apiRequest } from '../../lib/api'
const { t } = useI18n()
const busy = ref(false), error = ref('')
const eligible = computed(() => currentUser.value?.approved && currentUser.value.emailVerified && currentUser.value.profileComplete)
const name = computed(() => currentUser.value?.profile?.displayName || currentUser.value?.profile?.name)
const image = computed(() => currentUser.value?.profile?.avatarUrl || currentUser.value?.profile?.logoUrl)
async function visibility(value) {
  if (!eligible.value || busy.value) return
  const id = currentUser.value.id
  busy.value = true; error.value = ''
  try {
    const response = await apiRequest('/me/account-visibility', { method: 'PUT', body: { hide_my_account: value ? 0 : 1 } })
    if (currentUser.value?.id === id) setCurrentUser(response.data)
  } catch (cause) { if (currentUser.value?.id === id) error.value = cause.message }
  finally { busy.value = false }
}
</script>
<template>
  <aside v-if="currentUser" class="activation-profile">
    <div class="activation-profile__identity"><img v-if="image" :src="image" :alt="name" /><span v-else><UserRound :size="26" aria-hidden="true" /></span><div><strong>{{ name }}</strong><small>{{ t(currentUser.accountType === 'creator' ? 'auth.creator' : 'auth.company') }}</small></div></div>
    <p class="activation-profile__status"><ShieldCheck :size="16" aria-hidden="true" />{{ t(eligible ? 'activation.approved' : 'activation.preparing') }}</p>
    <SwitchField :model-value="currentUser.hide_my_account === 0 ? 1 : 0" :label="t('activation.publicProfile')" :description="t(eligible ? 'activation.visibilityHint' : 'activation.visibilityLocked')" :disabled="!eligible || busy" @update:model-value="visibility" />
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <LocalizedLink class="text-link" :to="{ name: 'account' }">{{ t('personalDashboard.editProfile') }} <ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></LocalizedLink>
  </aside>
</template>
