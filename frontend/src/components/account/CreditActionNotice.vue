<script setup>
import { computed, onMounted } from 'vue'
import { Coins } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { creditAccount, refreshCredits } from '../../composables/useCredits'
import LocalizedLink from '../shared/LocalizedLink.vue'

const props = defineProps({ action: { type: String, required: true, validator: (value) => ['application', 'campaign'].includes(value) } })
const { t } = useI18n()
const cost = computed(() => creditAccount.value?.settings[props.action === 'application' ? 'applicationCost' : 'campaignCost'])
const insufficient = computed(() => creditAccount.value && !creditAccount.value.unlimited && creditAccount.value.balance < cost.value)
onMounted(() => { refreshCredits().catch(() => {}) })
</script>

<template>
  <div v-if="creditAccount" class="credit-action-notice" :class="{ 'credit-action-notice--low': insufficient }" role="status">
    <Coins :size="18" aria-hidden="true" />
    <p>{{ creditAccount.unlimited ? t('credits.freeAction') : t('credits.actionCost', { cost, balance: creditAccount.balance }) }} <span v-if="insufficient">{{ t('credits.insufficient') }}</span></p>
    <LocalizedLink :to="{ name: 'account-credits' }">{{ t('credits.manage') }}</LocalizedLink>
  </div>
</template>

<style scoped>
.credit-action-notice { display:flex; align-items:center; flex-wrap:wrap; gap:10px; padding:14px; border:1px solid #dce5dc; border-radius:12px; background:#edf2eb; color:var(--forest); font-size:13px; }
.credit-action-notice p { flex:1; min-width:160px; margin:0; line-height:1.6; }
.credit-action-notice a { text-decoration:underline; }
.credit-action-notice--low { background:#fff0e1; border-color:#efcfb5; }
</style>
