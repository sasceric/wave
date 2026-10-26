<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Clock, Mail, RotateCw, Send } from '@lucide/vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiRequest, formatDate } from '../../lib/api'

// Profile completion emails (days 1, 4, 10, 21) for one account: history, next
// scheduled email, and manual "send again" / "send next now" for administrators.
const props = defineProps({
  reminders: { type: Object, required: true },
  kind: { type: String, required: true },
  id: { type: Number, required: true },
  strength: { type: Number, default: null },
})
const emit = defineEmits(['updated'])
const { t } = useI18n()
const busy = ref(0)
const error = ref('')
const notice = ref('')
const last = computed(() => props.reminders.lastStage)
const next = computed(() => props.reminders.nextStage)

function stageState(stage) {
  if (stage.sent) return stage.sentAt ? 'sent' : 'sentEarlier'
  if (stage.stage === next.value && props.reminders.status === 'active' && props.reminders.nextAt) return 'scheduled'
  return 'waiting'
}

async function send(stage) {
  if (busy.value) return
  busy.value = stage
  error.value = ''
  notice.value = ''
  try {
    const { data } = await apiRequest(`/admin/catalog/${props.kind}/${props.id}/profile-reminders`, { method: 'POST', body: { stage } })
    notice.value = t('adminProfiles.reminders.sentNow', { n: stage })
    emit('updated', data)
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = 0
  }
}
</script>

<template>
  <section class="admin-profile-reminders" :aria-label="t('adminProfiles.reminders.title')">
    <header>
      <h3>{{ t('adminProfiles.reminders.title') }}</h3>
      <span class="admin-profile-reminders__status" :class="`is-${reminders.status}`">{{ t(`adminProfiles.reminders.status.${reminders.status}`) }}<template v-if="strength !== null"> · {{ strength }}%</template></span>
    </header>
    <ol>
      <li v-for="stage in reminders.stages" :key="stage.stage" :class="`is-${stageState(stage)}`">
        <span class="admin-profile-reminders__icon" aria-hidden="true">
          <Check v-if="stage.sent" :size="13" stroke-width="3" />
          <Clock v-else-if="stageState(stage) === 'scheduled'" :size="13" />
          <Mail v-else :size="13" />
        </span>
        <span class="admin-profile-reminders__label">{{ t('adminProfiles.reminders.email', { n: stage.stage, day: stage.day }) }}</span>
        <span class="admin-profile-reminders__when">
          <template v-if="stageState(stage) === 'sent'">{{ t('adminProfiles.reminders.sentOn', { date: formatDate(stage.sentAt) }) }}<template v-if="stage.manual"> · {{ t('adminProfiles.reminders.manual') }}</template><template v-if="stage.sends > 1"> · {{ t('adminProfiles.reminders.times', { count: stage.sends }) }}</template></template>
          <template v-else-if="stageState(stage) === 'sentEarlier'">{{ t('adminProfiles.reminders.sentEarlier') }}</template>
          <template v-else-if="stageState(stage) === 'scheduled'">{{ t('adminProfiles.reminders.scheduled', { date: formatDate(reminders.nextAt) }) }}</template>
          <template v-else>{{ t('adminProfiles.reminders.notSent') }}</template>
        </span>
      </li>
    </ol>
    <div v-if="reminders.canSend" class="admin-profile-reminders__actions">
      <button v-if="last" class="button button--tonal" type="button" :disabled="Boolean(busy)" @click="send(last)">
        <RotateCw :size="15" aria-hidden="true" />{{ t(busy === last ? 'adminProfiles.reminders.sending' : 'adminProfiles.reminders.again', { n: last }) }}
      </button>
      <button v-if="next" class="button button--dark" type="button" :disabled="Boolean(busy)" @click="send(next)">
        <Send :size="15" aria-hidden="true" />{{ t(busy === next ? 'adminProfiles.reminders.sending' : 'adminProfiles.reminders.sendNext', { n: next }) }}
      </button>
    </div>
    <p v-else-if="['complete', 'tips_disabled', 'pending_approval'].includes(reminders.status)" class="admin-profile-reminders__hint">{{ t(`adminProfiles.reminders.hint.${reminders.status}`) }}</p>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <p v-else-if="notice" class="admin-profile-reminders__notice" role="status">{{ notice }}</p>
  </section>
</template>

<style lang="scss" src="../../scss/components/admin/AdminProfileReminders.scss"></style>
