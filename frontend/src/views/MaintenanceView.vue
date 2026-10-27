<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ArrowRight, Check, Mail, RefreshCw, Wrench } from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveWordmark from '../components/shared/WaveWordmark.vue'
import { loadMaintenance, maintenanceState } from '../composables/useMaintenance'
import { currentUser, setCurrentUser } from '../composables/useCurrentUser'
import { logoutAccount } from '../lib/pushSession'
import { localizedRouteName } from '../routePaths'

const { t, locale } = useI18n()
const router = useRouter()
const busy = ref(false)
const error = ref('')
const checked = ref(false)
const preview = computed(() => maintenanceState.value?.bypass === true)
let disposed = false
onBeforeUnmount(() => { disposed = true })
async function checkAgain() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const state = await loadMaintenance()
    if (disposed) return
    if (!state.enabled) await router.replace({ name: localizedRouteName('home', locale.value) })
    else checked.value = true
  } catch (cause) { if (!disposed) error.value = cause.message }
  finally { busy.value = false }
}
async function adminLogin() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    if (currentUser.value) { await logoutAccount(); setCurrentUser(null) }
    await router.push({ name: localizedRouteName('login', locale.value) })
  } catch (cause) { error.value = cause.message }
  finally { busy.value = false }
}
</script>

<template>
  <div class="maintenance-page">
    <header class="maintenance-page__header"><WaveWordmark :aria-label="t('app.home')" /><span class="maintenance-page__status"><span></span>{{ t('maintenance.status') }}</span></header>
    <main class="maintenance-page__main">
      <div class="maintenance-page__art" aria-hidden="true">
        <div class="maintenance-page__orbit"></div>
        <div class="maintenance-page__tile maintenance-page__tile--back"><span></span><span></span><span></span></div>
        <div class="maintenance-page__tile maintenance-page__tile--front"><Check :size="26" /><span></span><span></span></div>
        <div class="maintenance-page__tool"><Wrench :size="46" :stroke-width="1.5" /></div>
        <span class="maintenance-page__spark maintenance-page__spark--one"></span><span class="maintenance-page__spark maintenance-page__spark--two"></span>
      </div>
      <div class="maintenance-page__copy">
        <p class="maintenance-page__eyebrow">{{ t('maintenance.eyebrow') }}</p>
        <h1>{{ t('maintenance.title') }} <em>{{ t('maintenance.titleEmphasis') }}</em></h1>
        <p class="maintenance-page__description">{{ t('maintenance.description') }}</p>
        <div class="maintenance-page__actions">
          <button class="button button--dark" type="button" :disabled="busy" @click="checkAgain"><RefreshCw :size="17" aria-hidden="true" />{{ t(busy ? 'maintenance.checking' : 'maintenance.retry') }}</button>
          <a class="maintenance-page__contact" href="mailto:info@wave.ba"><Mail :size="17" aria-hidden="true" />{{ t('maintenance.contact') }}</a>
        </div>
        <p v-if="checked" class="maintenance-page__hint" role="status">{{ t('maintenance.stillActive') }}</p>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <LocalizedLink v-if="preview" class="maintenance-page__admin" :to="{ name: 'account-settings' }">{{ t('maintenance.adminBack') }}<ArrowRight :size="16" aria-hidden="true" /></LocalizedLink>
      </div>
    </main>
    <footer class="maintenance-page__footer"><span>{{ t('maintenance.footer') }}</span><button v-if="!preview" type="button" :disabled="busy" @click="adminLogin">{{ t('maintenance.adminLogin') }}</button><span v-else>{{ t('maintenance.preview') }}</span></footer>
  </div>
</template>

<style lang="scss" src="../scss/views/MaintenanceView.scss"></style>
