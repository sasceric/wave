<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Check, Circle, Mail, ShieldCheck } from '@lucide/vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { loadCurrentUser } from '../../composables/useCurrentUser'
import { useEmailVerification } from '../../composables/useEmailVerification'
const props = defineProps({ user: { type: Object, required: true } })
const { t } = useI18n()
const refreshing = ref(false)
const { sending, notice, error, resend } = useEmailVerification(() => props.user)
const creator = computed(() => props.user.accountType === 'creator')
const profile = computed(() => props.user.profile || {})
const steps = computed(() => [
  { key: 'email', done: props.user.emailVerified, action: 'email' },
  { key: 'contact', done: Boolean(props.user.phone?.trim() && props.user.city?.trim() && props.user.countryCode), tab: 'about' },
  { key: 'identity', done: Boolean((profile.value.displayName || profile.value.name || '').trim().length >= 2 && (creator.value ? profile.value.categories?.length : profile.value.industries?.length || profile.value.industry?.trim())), tab: 'about' },
])
const complete = computed(() => steps.value.filter(step => step.done).length)
const optional = computed(() => creator.value ? [
  { key: 'networks', tab: 'social', done: profile.value.socialProfiles?.length > 0 },
  { key: 'works', tab: 'portfolio', done: profile.value.portfolio?.length > 0 },
  { key: 'packages', tab: 'packages', done: profile.value.packages?.length > 0 },
] : [{ key: 'company', tab: 'about', done: Boolean(profile.value.logoUrl && profile.value.about?.trim()) }])
async function refreshStatus() {
  if (refreshing.value) return
  const id = props.user.id
  refreshing.value = true; error.value = ''
  try { await loadCurrentUser() }
  catch (cause) { if (id === props.user.id) error.value = cause.message }
  finally { refreshing.value = false }
}
onMounted(() => window.addEventListener('wave:inbox-refresh', refreshStatus))
onBeforeUnmount(() => window.removeEventListener('wave:inbox-refresh', refreshStatus))
</script>
<template>
  <section class="activation-panel">
    <header><div><p class="activation-panel__eyebrow">{{ t('activation.start') }}</p><h2>{{ t('activation.title') }}</h2><p>{{ t('activation.hint') }}</p></div><span class="activation-panel__progress">{{ complete }}/3</span></header>
    <div class="activation-panel__meter" role="progressbar" :aria-label="t('activation.progress')" :aria-valuenow="complete" aria-valuemin="0" aria-valuemax="3"><span :style="{ width: `${complete / 3 * 100}%` }"></span></div>
    <ol class="activation-panel__steps">
      <li v-for="step in steps" :key="step.key" :class="{ 'is-done': step.done }">
        <span class="activation-panel__check"><Check v-if="step.done" :size="18" aria-hidden="true" /><Circle v-else :size="17" aria-hidden="true" /></span>
        <div><strong>{{ t(`activation.${step.key}Title`) }}</strong><p>{{ t(`activation.${step.key}Hint`) }}</p></div>
        <Check v-if="step.done" :size="16" aria-hidden="true" />
        <button v-else-if="step.action === 'email'" class="button button--outline" type="button" :disabled="sending" @click="resend"><Mail :size="14" aria-hidden="true" />{{ t('activation.resend') }}</button>
        <LocalizedLink v-else class="button button--outline" :to="{ name: 'account', query: { tab: step.tab, edit: '1' } }">{{ t('activation.complete') }}<ArrowRight :size="14" aria-hidden="true" /></LocalizedLink>
      </li>
    </ol>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage><StatusMessage v-if="notice">{{ notice }}</StatusMessage>
    <div class="activation-panel__review"><ShieldCheck :size="23" aria-hidden="true" /><div><strong>{{ t(complete === 3 ? 'activation.reviewTitle' : 'activation.lockedTitle') }}</strong><p>{{ t(complete === 3 ? 'activation.reviewHint' : 'activation.lockedHint') }}</p></div></div>
    <div class="activation-panel__optional"><h3>{{ t('activation.optional') }}</h3><p>{{ t('activation.optionalHint') }}</p><LocalizedLink v-for="item in optional" :key="item.key" :to="{ name: 'account', query: { tab: item.tab, edit: '1' } }"><Check v-if="item.done" :size="16" aria-hidden="true" /><Circle v-else :size="16" aria-hidden="true" /><span>{{ t(`activation.optional_${item.key}`) }}</span><ArrowRight :size="15" aria-hidden="true" /></LocalizedLink></div>
  </section>
</template>
