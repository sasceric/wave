<script setup>
import { useI18n } from 'vue-i18n'
import CardImage from '../shared/CardImage.vue'
import { CAMPAIGN_PLACEHOLDER } from '../../lib/marketplace'
defineProps({ row: { type: Object, required: true }, campaign: Boolean })
const { t } = useI18n()
</script>

<template>
  <span class="admin-profile-identity" :class="{ 'admin-profile-identity--company': row.accountType === 'company' || campaign, 'admin-profile-identity--campaign': campaign }">
    <span class="admin-profile-identity__image"><CardImage :image="campaign ? row.coverImage : row.profileImage" :src="campaign ? row.coverImageUrl || CAMPAIGN_PLACEHOLDER : row.profileImageUrl || ''" :sizes="campaign ? '52px' : '36px'" alt="" /></span>
    <strong>{{ row.title || row.displayName || row.name || row.email }}<span v-if="row.isAdmin" class="admin-profile-identity__badge">{{ t('adminProfiles.admin.badge') }}</span></strong>
  </span>
</template>

<style scoped lang="scss">
.admin-profile-identity {
  display: flex; align-items: center; gap: 10px; min-width: 170px;
  strong { font-size: 12px; font-weight: 600; overflow-wrap: anywhere; }
  &__image { display: block; flex: 0 0 36px; width: 36px; height: 36px; overflow: hidden; border-radius: 50%; }
  &--company &__image { border-radius: 8px; }
  &--campaign &__image { flex-basis: 52px; width: 52px; height: 40px; }
  &__badge { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; color: #fff; background: var(--forest); font-size: 10px; font-weight: 700; vertical-align: 1px; }
}
</style>
