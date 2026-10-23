<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Check } from '@lucide/vue'
import { currentUser } from '../../composables/useCurrentUser'
import { rememberRegistrationIntent } from '../../lib/registrationIntent'
import { localizedPath } from '../../routePaths'
import LocalizedLink from './LocalizedLink.vue'

const props = defineProps({
  audience: { type: String, required: true, validator: value => ['creator', 'company'].includes(value) },
  variant: { type: String, default: 'banner', validator: value => ['home', 'banner', 'sidebar', 'profile', 'campaign'].includes(value) },
  returnTo: { type: String, default: '' },
  context: { type: String, default: '', validator: value => ['', 'companyDirectory'].includes(value) },
})
const { t, locale } = useI18n()
const copyKey = computed(() => `registrationPrompts.${props.context || props.variant + (props.audience === 'creator' ? 'Creator' : 'Company')}`)
const destination = computed(() => props.returnTo || (
  props.audience === 'company' && ['home', 'banner'].includes(props.variant) && !props.context
    ? localizedPath('account-campaign-create', locale.value)
    : ''
))
const detail = computed(() => ['profile', 'campaign'].includes(props.variant))
const benefitKeys = computed(() => props.variant === 'campaign'
  ? ['campaignSaved', 'campaignRequirements']
  : ['benefitWork', 'benefitCampaign', 'benefitMessages'])
const registrationRoute = computed(() => ({
  name: 'register',
  query: { accountType: props.audience, ...(destination.value ? { returnTo: destination.value } : {}) },
}))
</script>

<template>
  <section v-if="!currentUser" class="registration-prompt" :class="[`registration-prompt--${variant}`, `registration-prompt--${audience}`]">
    <div class="registration-prompt__copy">
      <p v-if="variant === 'home' || variant === 'campaign'" class="eyebrow">{{ t(`${copyKey}.eyebrow`) }}</p>
      <component :is="variant === 'sidebar' ? 'h3' : 'h2'" class="registration-prompt__title">
        {{ t(`${copyKey}.title`) }}
        <span v-if="variant === 'home'">{{ t(`${copyKey}.titleTail`) }}</span>
      </component>
      <p class="registration-prompt__description">{{ t(`${copyKey}.description`) }}</p>
    </div>
    <ul v-if="variant === 'profile'" class="registration-prompt__benefits">
      <li v-for="key in benefitKeys" :key="key"><Check :size="20" aria-hidden="true" /><span>{{ t(`registrationPrompts.${key}`) }}</span></li>
    </ul>
    <LocalizedLink class="button registration-prompt__action" :class="{ 'button--dark': audience === 'company' || detail, 'button--text': variant === 'sidebar' }" :to="registrationRoute" @click="rememberRegistrationIntent(destination, audience)">
      {{ t(`${copyKey}.action`) }}<ArrowRight :size="21" aria-hidden="true" />
    </LocalizedLink>
    <ul v-if="variant === 'campaign'" class="registration-prompt__benefits">
      <li v-for="key in benefitKeys" :key="key"><Check :size="20" aria-hidden="true" /><span>{{ t(`registrationPrompts.${key}`) }}</span></li>
    </ul>
    <p v-if="detail" class="registration-prompt__login">
      {{ t('registrationPrompts.hasAccount') }}
      <LocalizedLink :to="{ name: 'login', query: { ...(returnTo ? { returnTo } : {}) } }" @click="rememberRegistrationIntent(returnTo, audience)">{{ t('registrationPrompts.signIn') }}</LocalizedLink>
    </p>
  </section>
</template>

<style lang="scss" src="../../scss/components/shared/RegistrationPrompt.scss"></style>
