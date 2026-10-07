<script setup>
import { watch } from 'vue'
import { Coins } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { currentUser } from '../../composables/useCurrentUser'
import { creditAccount, refreshCredits } from '../../composables/useCredits'
import LocalizedLink from '../shared/LocalizedLink.vue'

const { t, locale } = useI18n()
watch([() => currentUser.value?.id, locale], () => { refreshCredits().catch(() => {}) }, { immediate: true })
</script>

<template>
  <LocalizedLink class="account-sidebar__link" :to="{ name: 'account-credits' }" :aria-label="creditAccount ? `${t('credits.title')}: ${creditAccount.unlimited ? t('credits.unlimited') : creditAccount.balance}` : t('credits.title')">
    <Coins :size="20" aria-hidden="true" />
    <span class="account-sidebar__text">{{ t('credits.title') }}</span>
    <strong v-if="creditAccount" class="account-sidebar__badge">{{ creditAccount.unlimited ? '∞' : creditAccount.balance }}</strong>
  </LocalizedLink>
</template>
