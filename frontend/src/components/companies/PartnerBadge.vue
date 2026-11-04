<script setup>
import { useI18n } from 'vue-i18n'
import { Handshake } from '@lucide/vue'

// "Partner Wave.ba": founding brands marked by administrators.
defineProps({ compact: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <span class="partner-badge" :class="{ 'partner-badge--compact': compact }" :title="t('companyDirectory.partnerHint')">
    <Handshake :size="compact ? 13 : 15" aria-hidden="true" />{{ t('companyDirectory.partner') }}
  </span>
</template>

<style scoped>
.partner-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  width: fit-content;
  padding: 5px 12px;
  color: #8a3f0c;
  font-size: .82rem;
  font-weight: 700;
  line-height: 1.2;
  background: #fbe3cf;
  border-radius: 999px;
  white-space: nowrap;
}

.partner-badge--compact { padding: 4px 10px; font-size: .74rem; }
</style>
